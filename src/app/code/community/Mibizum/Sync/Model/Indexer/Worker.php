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
        // Delete by the SKU captured at enqueue time: documents are keyed by SKU,
        // and the product no longer exists to re-derive it (deleting by entity_id
        // would be a no-op against the SKU-keyed index).
        $skus = array();
        $queueIds = array();
        foreach ($deleteEntries as $e) {
            $queueIds[] = (int) $e['queue_id'];
            $sku = isset($e['sku']) ? trim((string) $e['sku']) : '';
            if ($sku !== '') {
                $skus[] = $sku;
            }
        }

        // Nothing identifiable to delete (e.g. legacy entries enqueued before the
        // sku column existed). They were no-ops before too, so just clear them
        // instead of letting them retry until they hit max_attempts.
        if (empty($skus)) {
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
                $client->deleteDocumentsByIds('', $skus);
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
            $docQueue  = array();   // doc id (SKU) => queue_id
            $deleteSku = array();   // SKUs to remove from this destination (mapper said skip)

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
                    // ensure it is absent from this destination.
                    $sku = trim((string) $product->getSku());
                    if ($sku !== '') {
                        $deleteSku[] = $sku;
                    }
                    $okCount[$qid]++;
                    continue;
                }

                $docs[] = $doc;
                $docQueue[$doc['id']] = $qid;
            }

            // Remove the not-indexable ones from this destination (best effort).
            if (!empty($deleteSku)) {
                try {
                    $client->deleteDocumentsByIds('', $deleteSku);
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

        for ($i = 0; $i < $maxBatches; $i++) {
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
