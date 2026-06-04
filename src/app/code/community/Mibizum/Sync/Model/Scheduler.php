<?php
/**
 * Mibizum_Sync_Model_Scheduler
 *
 * Scheduled tasks:
 *  - fullReindex: enqueues ALL products and processes the whole queue.
 *    Scheduled in config.xml with the cron expression "0 3 * * *" (daily 03:00).
 *  - drainQueue: runs the worker to empty the pending queue.
 *    (Useful when called on a finer interval if desired.)
 *
 * Compatible with PHP 5.4 through 8.x.
 */
class Mibizum_Sync_Model_Scheduler
{
    /**
     * Full reindex. Useful to guarantee consistency even if the observer missed
     * an event.
     *
     * @param string $trigger 'cron' | 'manual' (admin button) | 'event'.
     */
    public function fullReindex($trigger = 'cron')
    {
        /** @var Mibizum_Sync_Helper_Data $helper */
        $helper = Mage::helper('mibizum_sync');

        // SAFE-DISABLE: if the connection is not active/configured
        // (`connection/enabled` + api key + api url) there is no backend to
        // publish to. In cron mode we return silently (no log spam, no queue
        // access); in manual mode we throw so the admin sees why nothing
        // happened and configures the connection first.
        //
        // NOTE: this checks `connection/enabled`, NOT `general/enabled`. The
        // storefront widget (`general/enabled`) is independent: with the
        // connection active the catalog is reindexed even while the overlay is
        // hidden, so the day the storefront widget is turned on the data is
        // already fresh and the dashboard has history.
        if (!$helper->isEnabledAnywhere()) {
            if ($trigger === 'manual') {
                throw new Exception(
                    $helper->__('Mibizum Sync is not connected: check Connection (Enabled + API key + API URL) before reindexing.')
                );
            }
            $helper->log('fullReindex skip: module not connected at any scope (connection/enabled off or missing credentials)', Zend_Log::DEBUG);
            return;
        }

        $startedAt    = microtime(true);
        $startedAtIso = gmdate('c');
        $helper->log('fullReindex starting (trigger=' . $trigger . ')');

        $totals = array(
            'processed' => 0,
            'succeeded' => 0,
            'failed'    => 0,
            'deleted'   => 0,
        );
        $status       = 'success';
        $errorMessage = null;

        try {
            // 0. Sync attribute schema. Before reindexing documents, make sure
            //    the tenant's search index marks the attributes the current
            //    module schema exposes as filterable/sortable/searchable.
            //    Without this, adding a new attribute (e.g. a new `doc_type`)
            //    and forgetting to sync would make queries using that filter
            //    return HTTP 400, and the native search Enter would fall back to
            //    MySQL. If the sync fails we log a WARN and CONTINUE: the reindex
            //    is still useful even with a possibly outdated schema.
            try {
                $this->applyEngineSettings();
            } catch (Exception $e) {
                $helper->log(
                    'fullReindex: applyEngineSettings failed (continuing reindex anyway): ' . $e->getMessage(),
                    Zend_Log::WARN
                );
            }

            // 1. Enqueue VISIBLE products (excludes configurable children).
            //    This greatly reduces load: a large catalog can have thousands
            //    of "simple" children that should NOT be indexed (they are not
            //    directly visible to the customer, they are only purchased
            //    through their configurable parent).
            $productIds = Mage::getModel('catalog/product')
                ->getCollection()
                ->addAttributeToFilter('status', Mage_Catalog_Model_Product_Status::STATUS_ENABLED)
                ->addAttributeToFilter(
                    'visibility',
                    array('in' => array(
                        Mage_Catalog_Model_Product_Visibility::VISIBILITY_IN_SEARCH,
                        Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH,
                    ))
                )
                ->getAllIds();

            /** @var Mibizum_Sync_Model_Indexer_Queue $queue */
            $queue = Mage::getSingleton('mibizum_sync/indexer_queue');
            $enqueued = $queue->enqueueBulkUpsert($productIds, 'full_reindex');

            $helper->log("fullReindex enqueued $enqueued products");

            // 2. Drain.
            /** @var Mibizum_Sync_Model_Indexer_Worker $worker */
            $worker = Mage::getSingleton('mibizum_sync/indexer_worker');
            $worker->setBatchSize(200)->setPushChunk(50);
            $totals = $worker->drainQueue();

            if (!empty($totals['failed']) && $totals['failed'] > 0) {
                $status = 'partial';
            }

            $elapsed = round(microtime(true) - $startedAt, 2);
            $helper->log(
                "fullReindex completed in {$elapsed}s",
                Zend_Log::INFO,
                $totals + array('elapsed_s' => $elapsed)
            );
        } catch (Exception $e) {
            $status       = 'failed';
            $errorMessage = $e->getMessage();
            $helper->log('fullReindex failed: ' . $errorMessage, Zend_Log::ERR);
        }

        // Report to the panel - best effort, never breaks the reindex if the
        // panel is down.
        $finishedAtIso = gmdate('c');
        $durationMs    = (int) round((microtime(true) - $startedAt) * 1000);
        $helper->reportSyncRun(array(
            'data_source_slug' => $helper->getDataSourceSlug(),
            'status'           => $status,
            'trigger'          => $trigger,
            'started_at'       => $startedAtIso,
            'finished_at'      => $finishedAtIso,
            'items_added'      => 0,
            'items_updated'    => isset($totals['succeeded']) ? (int) $totals['succeeded'] - (int) (isset($totals['deleted']) ? $totals['deleted'] : 0) : 0,
            'items_removed'    => isset($totals['deleted']) ? (int) $totals['deleted'] : 0,
            'items_failed'     => isset($totals['failed']) ? (int) $totals['failed'] : 0,
            'duration_ms'      => $durationMs,
            'error_message'    => $errorMessage,
            'meta'             => array(
                'processed' => isset($totals['processed']) ? (int) $totals['processed'] : 0,
                'enqueued'  => isset($enqueued) ? (int) $enqueued : null,
            ),
        ));

        // Record the date and result of the last full reindex so the admin
        // panel can show it. The config cache is cleared so the value is visible
        // on the next load (full reindex is infrequent).
        try {
            $cfg = Mage::getConfig();
            $cfg->saveConfig('mibizum_sync/reindex/last_full_at', $finishedAtIso);
            $cfg->saveConfig('mibizum_sync/reindex/last_full_status', $status);
            Mage::app()->getCacheInstance()->cleanType('config');
        } catch (Exception $e) {
            $helper->log('fullReindex: could not record the date: ' . $e->getMessage(), Zend_Log::WARN);
        }
    }

    /**
     * Enqueue phase of a full reindex WITHOUT draining. Used by the admin
     * Reindex console for a non-blocking, progress-tracked reindex: the console
     * enqueues here (fast) and then drains in small batches via repeated
     * progress polls, so the merchant sees a live counter instead of a frozen
     * request. The synchronous fullReindex() above is still used by cron/CLI.
     *
     * @return int Number of products enqueued.
     * @throws Exception if the module is not connected anywhere.
     */
    public function enqueueFullReindex()
    {
        /** @var Mibizum_Sync_Helper_Data $helper */
        $helper = Mage::helper('mibizum_sync');
        if (!$helper->isEnabledAnywhere()) {
            throw new Exception(
                $helper->__('Mibizum Sync is not connected: check Connection (Enabled + API key + API URL) before reindexing.')
            );
        }

        // Parity with the classic synchronous fullReindex(): re-apply the
        // attribute schema to the engine BEFORE publishing, so the
        // progress-tracked console reindex also fixes a possible schema/index
        // desync (otherwise a newly-added attribute filter could 400 and fall
        // back to MySQL). Best-effort: a failure here must not abort the reindex.
        try {
            $this->applyEngineSettings();
        } catch (Exception $e) {
            $helper->log(
                'enqueueFullReindex: applyEngineSettings failed (continuing reindex anyway): ' . $e->getMessage(),
                Zend_Log::WARN
            );
        }

        $productIds = Mage::getModel('catalog/product')
            ->getCollection()
            ->addAttributeToFilter('status', Mage_Catalog_Model_Product_Status::STATUS_ENABLED)
            ->addAttributeToFilter(
                'visibility',
                array('in' => array(
                    Mage_Catalog_Model_Product_Visibility::VISIBILITY_IN_SEARCH,
                    Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH,
                ))
            )
            ->getAllIds();

        /** @var Mibizum_Sync_Model_Indexer_Queue $queue */
        $queue    = Mage::getSingleton('mibizum_sync/indexer_queue');
        $enqueued = (int) $queue->enqueueBulkUpsert($productIds, 'full_reindex');
        $helper->log("enqueueFullReindex enqueued $enqueued products (progress-tracked)");
        return $enqueued;
    }

    /**
     * (An earlier version had applyEngineSettings() here, sending
     * searchable/filterable/sortable + rankingRules straight to the search
     * engine. In this module it does not work that way - the module has no
     * direct engine access; the Mibizum backend applies settings centrally.
     *
     * If a future version lets the merchant declare custom searchable/filterable
     * attributes, it will be via POST /api/v1/schema/declare-attributes. For
     * now, Mibizum_Sync_Model_Config_Schema is only used internally so the
     * caller knows which fields the ProductMapper publishes.)
     */

    /**
     * Drain the pending queue. Callable more frequently for low latency between
     * a catalog change and the search engine being up to date.
     */
    public function drainQueue()
    {
        // SAFE-DISABLE: with no active connection there is nothing to drain (see
        // the note in fullReindex). The Worker checks this too, but we cut here
        // to avoid touching the database on every cron tick of an unconfigured
        // store.
        $helper = Mage::helper('mibizum_sync');
        if (!$helper->isEnabledAnywhere()) {
            return;
        }
        try {
            /** @var Mibizum_Sync_Model_Indexer_Worker $worker */
            $worker = Mage::getSingleton('mibizum_sync/indexer_worker');
            $worker->drainQueue(50);
        } catch (Exception $e) {
            $helper->log('drainQueue failed: ' . $e->getMessage(), Zend_Log::ERR);
        }
    }

    /**
     * Sync the attribute schema (searchable/filterable/sortable) with the
     * tenant's search index via POST /api/v1/index/settings.
     *
     * This guards against schema/index desync (which would make the native
     * search Enter fall back to MySQL). We fix it by construction: every
     * fullReindex re-applies the schema before publishing documents. It is also
     * exposed as its own cron job (mibizum_sync_apply_settings, daily at 03:30)
     * to re-apply even without a reindex (usually unnecessary, but defensive).
     *
     * @return array Mibizum backend response (taskUid + indexUid).
     * @throws Exception on HTTP != 2xx or a network failure.
     */
    public function applyEngineSettings()
    {
        /** @var Mibizum_Sync_Helper_Data $helper */
        $helper = Mage::helper('mibizum_sync');

        $apiUrl = $helper->getApiUrl();
        $apiKey = $helper->getApiKey();   // indexer scope (not search) - this endpoint requires scope=indexer
        if (!$apiUrl || !$apiKey) {
            throw new Exception('applyEngineSettings: api_url or api_key not configured');
        }

        $schema = Mibizum_Sync_Model_Config_Schema::buildSearchSchema();

        $payload = array(
            'searchableAttributes' => $schema['searchable'],
            'filterableAttributes' => $schema['filterable'],
            'sortableAttributes'   => $schema['sortable'],
        );
        $slug = $helper->getDataSourceSlug();
        if ($slug) {
            $payload['source'] = $slug;
        }

        $url = rtrim($apiUrl, '/') . '/api/v1/index/settings';
        $ch  = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $helper->getTimeoutSeconds(),
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER     => array(
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ),
        ));
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new Exception('applyEngineSettings: curl error - ' . $err);
        }
        if ($code !== 200 && $code !== 202) {
            throw new Exception(sprintf(
                'applyEngineSettings: backend HTTP %d - %s',
                $code, substr((string) $body, 0, 300)
            ));
        }

        $json = json_decode((string) $body, true);
        $helper->log(
            'applyEngineSettings OK: taskUid=' . (isset($json['taskUid']) ? $json['taskUid'] : '?') .
            ' searchable=' . count($schema['searchable']) .
            ' filterable=' . count($schema['filterable']) .
            ' sortable=' . count($schema['sortable']),
            Zend_Log::INFO
        );
        return is_array($json) ? $json : array();
    }
}
