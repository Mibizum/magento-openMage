<?php
/**
 * Mibizum_Sync_Model_Indexer_Worker
 *
 * Processes a queue batch: for each entry it maps the product and pushes it to
 * the search engine. If everything in the batch goes OK they are marked
 * completed; if there are errors, the failed ones are released and the OK ones
 * stay completed.
 *
 * Called from: cron (every N minutes), CLI (n98-magerun), or on-demand in admin.
 *
 * Compatible with PHP 5.4 through 8.x.
 */
class Mibizum_Sync_Model_Indexer_Worker
{
    /** @var int Batch size per run. */
    protected $_batchSize = 100;

    /** @var int Products sent to the search engine in a single HTTP call. */
    protected $_pushChunk = 50;

    /**
     * Process ONE batch.
     *
     * Multi-store fan-out: each queued product is published into EVERY connected
     * store-view's catalog (one Mibizum data source per store-view), mapped in
     * that store-view's scope (translated attributes, store price, store URL).
     * Store-views that resolve to the same destination (same API key + URL +
     * slug) are deduped, so a single-store merchant publishes exactly once.
     *
     * A queue entry is only marked complete when it succeeded in ALL of its
     * target destinations; if any destination fails, it is released to retry
     * (re-pushing to an already-OK destination is an idempotent upsert).
     *
     * @return array Summary {processed, succeeded, failed, deleted}
     */
    public function processBatch()
    {
        /** @var Mibizum_Sync_Model_Indexer_Queue $queue */
        $queue = Mage::getSingleton('mibizum_sync/indexer_queue');
        /** @var Mibizum_Sync_Model_Indexer_ProductMapper $mapper */
        $mapper = Mage::getSingleton('mibizum_sync/indexer_productMapper');
        /** @var Mibizum_Sync_Helper_Data $helper */
        $helper = Mage::helper('mibizum_sync');

        $result = array(
            'processed' => 0,
            'succeeded' => 0,
            'failed'    => 0,
            'deleted'   => 0,
        );

        // SAFE-DISABLE: backstop for ALL paths that drain the queue (cron drain,
        // admin "Drain" button, fullReindex). If NO store-view is connected we
        // claim nothing, so we do not even touch `mibizum_sync_index_queue` on a
        // store with the module turned off (checked at any scope, so a merchant
        // who only configured per-store-view is covered too).
        $destinations = $this->_resolveDestinations($helper);
        if (empty($destinations)) {
            return $result;
        }

        // Pause: filter out paused store-views. If all destinations are paused
        // we return without claiming, so the queue stays intact and resumes
        // where it left off when the merchant unpauses.
        $destinations = $this->_filterPausedDestinations($helper, $destinations);
        if (empty($destinations)) {
            return $result;
        }

        $token = $this->_workerToken();
        $entries = $queue->claimBatch($this->_batchSize, $token);
        if (empty($entries)) {
            return $result;
        }
        $result['processed'] = count($entries);

        // Partition by operation.
        $upsertEntries = array();
        $deleteEntries = array();
        foreach ($entries as $e) {
            if ($e['operation'] === Mibizum_Sync_Model_Indexer_Queue::OP_UPSERT) {
                $upsertEntries[] = $e;
            } else {
                $deleteEntries[] = $e;
            }
        }

        // Save the current store; we switch context per destination and MUST
        // restore it afterwards (PHP 5.4 has no `finally`, so we restore on every
        // exit path, including the fatal catch).
        $prevStore = Mage::app()->getStore()->getId();

        try {
            if (!empty($deleteEntries)) {
                $this->_processDeletes($queue, $helper, $destinations, $deleteEntries, $result);
            }
            if (!empty($upsertEntries)) {
                $this->_processUpserts($queue, $mapper, $helper, $destinations, $upsertEntries, $result);
            }
        } catch (Exception $fatal) {
            // Unexpected fatal: restore store, release the whole claim so it
            // retries, and bail. Never leave the store context switched.
            Mage::app()->setCurrentStore($prevStore);
            $allQueueIds = array();
            foreach ($entries as $e) {
                $allQueueIds[] = (int) $e['queue_id'];
            }
            try { $queue->fail($allQueueIds, 'Worker fatal: ' . $fatal->getMessage()); } catch (Exception $e2) {}
            $result['failed'] = count($allQueueIds);
            $helper->log('Worker batch fatal: ' . $fatal->getMessage(), Zend_Log::ERR);
            return $result;
        }

        Mage::app()->setCurrentStore($prevStore);

        $helper->log(
            sprintf(
                'Worker batch processed=%d succeeded=%d failed=%d deleted=%d destinations=%d',
                $result['processed'], $result['succeeded'], $result['failed'], $result['deleted'], count($destinations)
            ),
            Zend_Log::INFO,
            $result
        );

        return $result;
    }

    /**
     * Resolve the distinct fan-out destinations: one entry per Mibizum catalog,
     * deduped by destination signature (API key + URL + slug). Each entry holds a
     * representative store-view id (the scope products are mapped in) and the set
     * of website ids that route to it.
     *
     * @param Mibizum_Sync_Helper_Data $helper
     * @return array signature => array('store' => int, 'websites' => array<int,bool>)
     */
    protected function _resolveDestinations($helper)
    {
        $destinations = array();
        foreach ($helper->getEnabledStoreViewIds() as $svId) {
            $sig = $helper->getDestinationSignature($svId);
            if (!isset($destinations[$sig])) {
                $destinations[$sig] = array('store' => $svId, 'websites' => array());
            }
            $wid = (int) Mage::app()->getStore($svId)->getWebsiteId();
            $destinations[$sig]['websites'][$wid] = true;
        }
        return $destinations;
    }

    /**
     * Deletes are fanned out to every destination (the product is already gone,
     * so we cannot know which catalogs held it). Completed only if every
     * destination accepted the delete; otherwise released to retry.
     */
    protected function _processDeletes($queue, $helper, $destinations, $deleteEntries, array &$result)
    {
        // Delete by the SANITIZED document id rebuilt from sku + product_id.
        // Documents are keyed by sanitizeDocId(sku, entity_id), and the product
        // no longer exists. The queue stores both sku and product_id at enqueue
        // time (onProductDeleteBefore).
        $docIds = array();
        $queueIds = array();
        foreach ($deleteEntries as $e) {
            $queueIds[] = (int) $e['queue_id'];
            $sku = isset($e['sku']) ? trim((string) $e['sku']) : '';
            $pid = isset($e['product_id']) ? (int) $e['product_id'] : 0;
            if ($sku !== '') {
                $docIds[] = Mibizum_Sync_Model_Indexer_ProductMapper::sanitizeDocId($sku, $pid);
            }
        }

        // Nothing identifiable to delete (e.g. legacy entries enqueued before the
        // sku column existed). They were no-ops before too, so just clear them
        // instead of letting them retry until they hit max_attempts.
        if (empty($docIds)) {
            $queue->complete($queueIds);
            $result['deleted'] += count($queueIds);
            $result['succeeded'] += count($queueIds);
            return;
        }

        $allOk = true;
        foreach ($destinations as $d) {
            Mage::app()->setCurrentStore($d['store']);
            $client = Mage::getModel('mibizum_sync/adapter_mibizum');
            try {
                $client->deleteDocumentsByIds('', $docIds);
            } catch (Exception $ex) {
                $allOk = false;
                $helper->log('Worker delete failed in a destination: ' . $ex->getMessage(), Zend_Log::ERR);
            }
        }

        if ($allOk) {
            $queue->complete($queueIds);
            $result['deleted'] += count($queueIds);
            $result['succeeded'] += count($queueIds);
        } else {
            $queue->fail($queueIds, 'delete failed in at least one destination');
            $result['failed'] += count($queueIds);
        }
    }

    /**
     * Upserts: for each destination, map every product assigned to that
     * destination's website(s) in that store-view's scope and push it. A queue
     * entry completes only when it succeeded in ALL its target destinations.
     */
    protected function _processUpserts($queue, $mapper, $helper, $destinations, $upsertEntries, array &$result)
    {
        // Per-queue-entry bookkeeping.
        $targetCount = array();   // queue_id => number of destinations it must reach
        $okCount     = array();   // queue_id => destinations reached OK
        $failed      = array();   // queue_id => bool (failed in >= 1 destination)
        $skip        = array();   // queue_ids with no target destination (complete now)

        // Product -> website ids (loaded once, lightweight, default scope).
        $resource = Mage::getResourceSingleton('catalog/product');
        $productWebsites = array();
        foreach ($upsertEntries as $e) {
            $pid = (int) $e['product_id'];
            $qid = (int) $e['queue_id'];
            $productWebsites[$pid] = array_map('intval', (array) $resource->getWebsiteIds($pid));

            $tc = 0;
            foreach ($destinations as $d) {
                if (array_intersect(array_keys($d['websites']), $productWebsites[$pid])) {
                    $tc++;
                }
            }
            $targetCount[$qid] = $tc;
            $okCount[$qid]     = 0;
            $failed[$qid]      = false;
            if ($tc === 0) {
                // Not assigned to any connected website -> nothing to index.
                $skip[] = $qid;
            }
        }

        // One pass per destination (batched HTTP per destination).
        foreach ($destinations as $d) {
            Mage::app()->setCurrentStore($d['store']);
            $client = Mage::getModel('mibizum_sync/adapter_mibizum');

            $docs      = array();   // docs to upsert in this destination
            $docQueue  = array();   // doc id (sanitized SKU) => queue_id
            $docSku    = array();   // doc id => original SKU (for collision diag)
            $deleteIds = array();   // SANITIZED doc ids to remove (mapper said skip)

            foreach ($upsertEntries as $e) {
                $pid = (int) $e['product_id'];
                $qid = (int) $e['queue_id'];
                if ($targetCount[$qid] === 0) {
                    continue;
                }
                if (!array_intersect(array_keys($d['websites']), $productWebsites[$pid])) {
                    continue; // this product does not belong to this destination
                }

                $product = Mage::getModel('catalog/product')->setStoreId($d['store'])->load($pid);
                if (!$product || !$product->getId()) {
                    // Gone since enqueue: nothing to index here, count as reached.
                    $okCount[$qid]++;
                    continue;
                }

                $doc = $mapper->map($product);
                if ($doc === null) {
                    // Not indexable in THIS store-view (visibility/price/website) ->
                    // ensure it is absent from this destination. Delete by the same
                    // sanitized id the upsert would have used (raw SKU won't match).
                    $sku = trim((string) $product->getSku());
                    if ($sku !== '') {
                        $deleteIds[] = Mibizum_Sync_Model_Indexer_ProductMapper::sanitizeDocId(
                            $sku, (int) $product->getId()
                        );
                    }
                    $okCount[$qid]++;
                    continue;
                }

                // Collision guard: two DISTINCT products whose SKUs sanitize to
                // the SAME Meili id (e.g. accents/punctuation: "FRA-PIÑA" vs
                // "FRA-PINA"). Meili keys by id, so the later doc SHADOWS the
                // earlier one AND the earlier's queue entry never reaches its
                // destination (it churns until max_attempts). Surface it loudly;
                // the real fix (hash-suffixed ids) needs an index clear, tracked
                // as a follow-up. Detection is per-batch (cheap best-effort): two
                // colliding SKUs in different drain batches won't be paired here.
                $docId = $doc['id'];
                if (isset($docQueue[$docId])) {
                    $helper->log(
                        sprintf(
                            'Worker: SKU collision - doc id "%s" produced by SKUs "%s" and "%s"; the latter shadows the former in the search index.',
                            $docId,
                            isset($docSku[$docId]) ? $docSku[$docId] : '?',
                            isset($doc['sku']) ? $doc['sku'] : '?'
                        ),
                        Zend_Log::WARN
                    );
                }
                $docs[] = $doc;
                $docQueue[$docId] = $qid;
                $docSku[$docId]   = isset($doc['sku']) ? $doc['sku'] : '';
            }

            // Remove the not-indexable ones from this destination (best effort).
            if (!empty($deleteIds)) {
                try {
                    $client->deleteDocumentsByIds('', $deleteIds);
                } catch (Exception $ex) {
                    $helper->log('Worker per-destination delete failed: ' . $ex->getMessage(), Zend_Log::WARN);
                }
            }

            // Push the docs in chunks.
            foreach (array_chunk($docs, $this->_pushChunk) as $chunk) {
                try {
                    $client->indexDocuments('', $chunk);
                    foreach ($chunk as $doc) {
                        $qid = $docQueue[$doc['id']];
                        $okCount[$qid]++;
                    }
                } catch (Exception $ex) {
                    foreach ($chunk as $doc) {
                        $qid = $docQueue[$doc['id']];
                        $failed[$qid] = true;
                    }
                    $helper->log('Worker upsert chunk failed in a destination: ' . $ex->getMessage(), Zend_Log::ERR);
                }
            }
        }

        // Resolve queue entries: complete only if every target destination was
        // reached and none failed; otherwise release to retry.
        $completeIds = $skip;
        $failIds     = array();
        foreach ($upsertEntries as $e) {
            $qid = (int) $e['queue_id'];
            if ($targetCount[$qid] === 0) {
                continue; // already in $skip
            }
            if (!$failed[$qid] && $okCount[$qid] >= $targetCount[$qid]) {
                $completeIds[] = $qid;
            } else {
                $failIds[] = $qid;
            }
        }

        if (!empty($completeIds)) {
            $queue->complete($completeIds);
            $result['succeeded'] += count($completeIds);
        }
        if (!empty($failIds)) {
            $queue->fail($failIds, 'upsert incomplete or failed in at least one destination');
            $result['failed'] += count($failIds);
        }
    }

    /**
     * Process the WHOLE queue until empty. Meant for CLI / cron full reindex.
     *
     * @param int $maxBatches Defensive cap.
     * @return array Accumulated summary.
     */
    public function drainQueue($maxBatches = 1000)
    {
        $totals = array(
            'batches'   => 0,
            'processed' => 0,
            'succeeded' => 0,
            'failed'    => 0,
            'deleted'   => 0,
        );

        /** @var Mibizum_Sync_Helper_Data $helper */
        $helper = Mage::helper('mibizum_sync');

        for ($i = 0; $i < $maxBatches; $i++) {
            // Between batches: if all destinations are now paused, stop
            // draining so the queue stays intact for when the merchant resumes.
            if ($this->_allDestinationsPaused($helper)) {
                $helper->log('drainQueue: all destinations paused, stopping', Zend_Log::INFO);
                break;
            }

            $r = $this->processBatch();
            $totals['batches']++;
            $totals['processed'] += $r['processed'];
            $totals['succeeded'] += $r['succeeded'];
            $totals['failed']    += $r['failed'];
            $totals['deleted']   += $r['deleted'];

            if ($r['processed'] === 0) {
                break;
            }
        }
        return $totals;
    }

    // -------------------------------------------------------------------------
    // Bulk file generation (Cloudflare-safe full reindex)
    // -------------------------------------------------------------------------

    /**
     * Build a JSONL.gz file with all mapped products for a single destination.
     *
     * Instead of sending products via HTTP one chunk at a time (which triggers
     * Cloudflare rate limits), this method writes them to a local file that is
     * then uploaded in a single HTTP request.
     *
     * @param array $productIds  Array of product entity_ids to process.
     * @param array $destination {store => int, websites => array<int,bool>}
     * @return array|null {filePath, count} or null if nothing to index.
     */
    public function buildBulkFile(array $productIds, array $destination)
    {
        if (empty($productIds)) {
            return null;
        }

        /** @var Mibizum_Sync_Model_Indexer_ProductMapper $mapper */
        $mapper = Mage::getSingleton('mibizum_sync/indexer_productMapper');
        /** @var Mibizum_Sync_Helper_Data $helper */
        $helper = Mage::helper('mibizum_sync');

        $dir = Mage::getBaseDir('var') . DS . 'mibizum';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $storeId = $destination['store'];
        $sig     = md5($storeId . '_' . implode('_', array_keys($destination['websites'])));
        $path    = $dir . DS . $sig . '.jsonl.gz';

        if (!function_exists('gzopen')) {
            $helper->log('buildBulkFile: gzopen not available (zlib missing)', Zend_Log::ERR);
            return null;
        }

        $gz = gzopen($path, 'wb9');
        if (!$gz) {
            $helper->log('buildBulkFile: could not open ' . $path, Zend_Log::ERR);
            return null;
        }

        $prevStore = Mage::app()->getStore()->getId();
        Mage::app()->setCurrentStore($storeId);

        $count  = 0;
        $websiteIds = array_keys($destination['websites']);

        foreach (array_chunk($productIds, 200) as $chunk) {
            foreach ($chunk as $pid) {
                $pid = (int) $pid;

                $resource = Mage::getResourceSingleton('catalog/product');
                $prodWebsites = array_map('intval', (array) $resource->getWebsiteIds($pid));
                if (!array_intersect($websiteIds, $prodWebsites)) {
                    continue;
                }

                $product = Mage::getModel('catalog/product')
                    ->setStoreId($storeId)
                    ->load($pid);
                if (!$product || !$product->getId()) {
                    continue;
                }

                $doc = $mapper->map($product);
                if ($doc === null) {
                    continue;
                }

                $line = json_encode($doc);
                if ($line !== false) {
                    gzwrite($gz, $line . "\n");
                    $count++;
                }

                $product->clearInstance();
            }
        }

        Mage::app()->setCurrentStore($prevStore);
        gzclose($gz);

        if ($count === 0) {
            @unlink($path);
            return null;
        }

        $helper->log(sprintf(
            'buildBulkFile: wrote %d products to %s (store=%d, size=%s)',
            $count, basename($path), $storeId,
            $this->_humanFileSize(filesize($path))
        ), Zend_Log::INFO);

        return array('filePath' => $path, 'count' => $count);
    }

    /**
     * Wait for a bulk upload task to complete by polling.
     *
     * @param Mibizum_Sync_Model_Adapter_Mibizum $client
     * @param string $taskId
     * @param int    $timeoutSeconds
     * @return array Final status {status, processed, total, errors, ...}
     */
    public function waitForBulkUpload($client, $taskId, $timeoutSeconds = 600)
    {
        $start = time();
        while (true) {
            $status = $client->pollBulkUpload($taskId);
            if (isset($status['status']) && ($status['status'] === 'done' || $status['status'] === 'error')) {
                return $status;
            }
            if ((time() - $start) >= $timeoutSeconds) {
                return array('status' => 'timeout', 'processed' => 0, 'total' => 0, 'errors' => 0);
            }
            sleep(3);
        }
    }

    /** @return string Human-readable file size. */
    protected function _humanFileSize($bytes)
    {
        $bytes = (int) $bytes;
        if ($bytes < 1024) return $bytes . 'B';
        if ($bytes < 1048576) return round($bytes / 1024, 1) . 'KB';
        return round($bytes / 1048576, 1) . 'MB';
    }

    /**
     * Remove paused store-views from the destination list.
     *
     * @param Mibizum_Sync_Helper_Data $helper
     * @param array $destinations
     * @return array
     */
    protected function _filterPausedDestinations($helper, $destinations)
    {
        $active = array();
        foreach ($destinations as $sig => $d) {
            if (!$helper->isPaused($d['store'])) {
                $active[$sig] = $d;
            }
        }
        return $active;
    }

    /**
     * @param Mibizum_Sync_Helper_Data $helper
     * @return bool
     */
    protected function _allDestinationsPaused($helper)
    {
        $destinations = $this->_resolveDestinations($helper);
        if (empty($destinations)) {
            return false;
        }
        // PHP 5.4: empty() cannot take a function return value directly.
        $active = $this->_filterPausedDestinations($helper, $destinations);
        return empty($active);
    }

    /** @return string Unique identifier for the current worker. */
    protected function _workerToken()
    {
        return gethostname() . '-' . getmypid() . '-' . substr(md5(microtime(true) . mt_rand()), 0, 6);
    }

    /** @param int $size */
    public function setBatchSize($size)
    {
        $this->_batchSize = max(1, (int) $size);
        return $this;
    }

    /** @param int $size */
    public function setPushChunk($size)
    {
        $this->_pushChunk = max(1, (int) $size);
        return $this;
    }
}
