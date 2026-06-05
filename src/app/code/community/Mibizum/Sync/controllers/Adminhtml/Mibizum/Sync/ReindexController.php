<?php
/**
 * Mibizum_Sync_Adminhtml_Mibizum_Sync_ReindexController
 *
 * Actions for the "Reindex" fieldset (inside General configuration).
 *
 * URL: /mcpanel/mibizum_sync_reindex/<action>/key/<formkey>/
 * Magento 1 admin convention: 2 underscores in the URL (mibizum_sync_reindex)
 * require 3 path levels under controllers/Adminhtml/ (Mibizum/Sync/Reindex).
 *
 *  - fullAction:  runs a full reindex (enqueues all products and re-applies
 *                 the index settings automatically).
 *  - drainAction: processes the pending queue.
 *  - statsAction: JSON with the queue and index status (for AJAX).
 *
 * Compatible with PHP 5.4+.
 */
class Mibizum_Sync_Adminhtml_Mibizum_Sync_ReindexController extends Mage_Adminhtml_Controller_Action
{
    protected function _isAllowed()
    {
        return Mage::getSingleton('admin/session')->isAllowed('mibizum_sync/search/reindex');
    }

    public function indexAction()
    {
        $this->loadLayout()
            ->_setActiveMenu('mibizum_sync/search/reindex')
            ->_addContent($this->getLayout()->createBlock('mibizum_sync/adminhtml_reindex'))
            ->renderLayout();
    }

    /**
     * If the request comes from AJAX (X-Requested-With: XMLHttpRequest), it
     * responds JSON {success, message} and returns true (the action already
     * responded). On a normal navigation it returns false and the action
     * continues to the _redirect.
     *
     * @param bool $success
     * @param string $message
     * @return bool
     */
    protected function _respondAjaxIfApplicable($success, $message)
    {
        if (!$this->getRequest()->isXmlHttpRequest()) {
            return false;
        }
        $this->getResponse()
            ->setHeader('Content-Type', 'application/json; charset=UTF-8', true)
            ->setBody(json_encode(array(
                'success' => (bool) $success,
                'message' => (string) $message,
            ), JSON_UNESCAPED_UNICODE));
        return true;
    }

    /**
     * CSRF guard for state-changing actions: require POST + a valid form_key
     * (the on-screen panel already POSTs both via AJAX). Returns false, after
     * emitting a JSON error (AJAX) or queuing an error + redirect (navigation),
     * when the request must be rejected.
     *
     * @return bool
     */
    protected function _guardWrite()
    {
        if ($this->getRequest()->isPost() && $this->_validateFormKey()) {
            return true;
        }
        $msg = $this->__('Your session expired or the security token is invalid. Please retry.');
        if (!$this->_respondAjaxIfApplicable(false, $msg)) {
            Mage::getSingleton('adminhtml/session')->addError($msg);
            $this->_redirect('adminhtml/system_config/edit', array('section' => 'mibizum_sync'));
        }
        return false;
    }

    /** Emit a JSON body and stop (helper for the AJAX endpoints). */
    protected function _emitJson(array $payload)
    {
        $this->getResponse()
            ->setHeader('Content-Type', 'application/json; charset=UTF-8', true)
            ->setBody(json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Start a full reindex.
     *
     *  - AJAX (the on-screen console): ENQUEUE only and return {total} fast. The
     *    console then drains in small batches via progressAction polls, showing a
     *    live counter. Buttons stay disabled until it finishes.
     *  - Navigation (no JS): falls back to the classic blocking full reindex so
     *    the action still works without JavaScript.
     */
    public function fullAction()
    {
        if (!$this->_guardWrite()) {
            return;
        }

        // No-JS fallback: synchronous full reindex (classic behavior).
        if (!$this->getRequest()->isXmlHttpRequest()) {
            try {
                Mage::getModel('mibizum_sync/scheduler')->fullReindex('manual');
                Mage::getSingleton('adminhtml/session')->addSuccess(
                    $this->__('Full reindex completed. Check the mibizum_sync.log log for details.')
                );
            } catch (Exception $e) {
                Mage::getSingleton('adminhtml/session')->addError($e->getMessage());
            }
            $this->_redirect('adminhtml/system_config/edit', array('section' => 'mibizum_sync'));
            return;
        }

        // AJAX: enqueue, then let the console drive the drain with live progress.
        $ok = false; $total = 0; $msg = '';
        try {
            $total = (int) Mage::getModel('mibizum_sync/scheduler')->enqueueFullReindex();
            $sess  = Mage::getSingleton('adminhtml/session');
            $sess->setMibizumReindexTotal($total);
            $sess->setMibizumReindexFailed(0);
            $sess->setMibizumReindexSucceeded(0);
            $sess->setMibizumReindexDeleted(0);
            $sess->setMibizumReindexStartedAt(gmdate('c'));
            // Report the START so the Superadmin sees this reindex "in progress"
            // live; keep the backend run id to close it precisely on finish.
            $runId = Mage::helper('mibizum_sync')->reportSyncRunStart('manual');
            $sess->setMibizumReindexSyncRunId($runId ? (int) $runId : null);
            // Mark "running" so a mid-reindex page reload resumes the progress view.
            $cfg = Mage::getConfig();
            $cfg->saveConfig('mibizum_sync/reindex/last_full_status', 'running');
            Mage::app()->getCacheInstance()->cleanType('config');
            $ok  = true;
            $msg = $this->__('Reindex started: %s product(s) queued.', $total);
        } catch (Exception $e) {
            $msg = $e->getMessage();
        }
        $this->_emitJson(array('success' => $ok, 'total' => $total, 'message' => $msg));
    }

    /**
     * Drains ONE small batch and reports live progress for the on-screen console.
     * Called once per second while a reindex runs. State-changing (drains), so it
     * requires POST + form_key like the other write actions.
     */
    public function progressAction()
    {
        if (!$this->_guardWrite()) {
            return;
        }

        $sess = Mage::getSingleton('adminhtml/session');
        try {
            /** @var Mibizum_Sync_Model_Indexer_Worker $worker */
            $worker = Mage::getSingleton('mibizum_sync/indexer_worker');
            // Small batch so each poll returns in ~1s and the counter moves live.
            $worker->setBatchSize(120)->setPushChunk(50);
            $totals = $worker->drainQueue(1);

            $failed = (int) $sess->getMibizumReindexFailed()
                    + (isset($totals['failed']) ? (int) $totals['failed'] : 0);
            $sess->setMibizumReindexFailed($failed);
            // Accumulate succeeded/deleted across polls too, so the sync-run we
            // report to the backend (and the panel) has honest item counts.
            $succeeded = (int) $sess->getMibizumReindexSucceeded()
                       + (isset($totals['succeeded']) ? (int) $totals['succeeded'] : 0);
            $sess->setMibizumReindexSucceeded($succeeded);
            $deleted = (int) $sess->getMibizumReindexDeleted()
                     + (isset($totals['deleted']) ? (int) $totals['deleted'] : 0);
            $sess->setMibizumReindexDeleted($deleted);

            $qstats  = Mage::getSingleton('mibizum_sync/indexer_queue')->getStats();
            $pending = isset($qstats['pending']) ? (int) $qstats['pending'] : 0;
            $locked  = isset($qstats['locked'])  ? (int) $qstats['locked']  : 0;
            $running = ($pending + $locked) > 0;

            $indexed = null;
            try {
                $st = Mage::getModel('mibizum_sync/adapter_mibizum')->getStats();
                $indexed = isset($st['numberOfDocuments']) ? (int) $st['numberOfDocuments'] : null;
            } catch (Exception $e) {
                $indexed = null;
            }

            $total        = (int) $sess->getMibizumReindexTotal();
            $justFinished = false;
            if (!$running) {
                $status = $failed > 0 ? 'partial' : 'success';
                $cfg = Mage::getConfig();
                $cfg->saveConfig('mibizum_sync/reindex/last_full_at', gmdate('c'));
                $cfg->saveConfig('mibizum_sync/reindex/last_full_status', $status);
                Mage::app()->getCacheInstance()->cleanType('config');

                // Close the sync-run we opened at start (Superadmin telemetry).
                // Best-effort; mirrors the mapping the classic fullReindex uses.
                $startedAt = $sess->getMibizumReindexStartedAt();
                $startMs   = $startedAt ? strtotime($startedAt) : null;
                $payload = array(
                    'status'        => $status,
                    'trigger'       => 'manual',
                    'started_at'    => $startedAt ?: gmdate('c'),
                    'finished_at'   => gmdate('c'),
                    'items_added'   => 0,
                    'items_updated' => max(0, $succeeded - $deleted),
                    'items_removed' => $deleted,
                    'items_failed'  => $failed,
                    'duration_ms'   => $startMs ? max(0, (int) round((microtime(true) - $startMs) * 1000)) : null,
                    'meta'          => array(
                        'processed' => $succeeded + $failed,
                        'enqueued'  => $total,
                    ),
                );
                $runId = $sess->getMibizumReindexSyncRunId();
                if ($runId) {
                    $payload['sync_run_id'] = (int) $runId;
                }
                Mage::helper('mibizum_sync')->reportSyncRun($payload);

                $sess->unsMibizumReindexTotal();
                $sess->unsMibizumReindexFailed();
                $sess->unsMibizumReindexSucceeded();
                $sess->unsMibizumReindexDeleted();
                $sess->unsMibizumReindexStartedAt();
                $sess->unsMibizumReindexSyncRunId();
                $justFinished = true;
            }

            $this->_emitJson(array(
                'success'      => true,
                'running'      => $running,
                'total'        => $total,
                'pending'      => $pending,
                'locked'       => $locked,
                'done'         => $total > 0 ? max(0, $total - $pending) : null,
                'indexed'      => $indexed,
                'failed'       => $failed,
                'justFinished' => $justFinished,
            ));
        } catch (Exception $e) {
            $this->_emitJson(array('success' => false, 'message' => $e->getMessage()));
        }
    }

    /** Only drains the pending queue (does not enqueue new ones). */
    public function drainAction()
    {
        if (!$this->_guardWrite()) {
            return;
        }
        $ok  = false;
        $msg = '';
        try {
            /** @var Mibizum_Sync_Model_Indexer_Worker $worker */
            $worker = Mage::getSingleton('mibizum_sync/indexer_worker');
            $totals = $worker->drainQueue(50);
            $ok  = true;
            $msg = $this->__('Queue processed: %s', json_encode($totals));
            Mage::getSingleton('adminhtml/session')->addSuccess($msg);
        } catch (Exception $e) {
            $msg = $e->getMessage();
            Mage::getSingleton('adminhtml/session')->addError($msg);
        }
        if ($this->_respondAjaxIfApplicable($ok, $msg)) {
            return;
        }
        $this->_redirect('adminhtml/system_config/edit', array('section' => 'mibizum_sync'));
    }

    /** Pause sync for the current store view (queue stays intact). */
    public function pauseAction()
    {
        if (!$this->_guardWrite()) {
            return;
        }
        $storeId = $this->_getStoreViewId();
        if (!$storeId) {
            $msg = $this->__('Select a store view to pause sync.');
            if (!$this->_respondAjaxIfApplicable(false, $msg)) {
                Mage::getSingleton('adminhtml/session')->addError($msg);
                $this->_redirect('adminhtml/system_config/edit', array('section' => 'mibizum_sync'));
            }
            return;
        }
        Mage::helper('mibizum_sync')->setPaused($storeId, true);
        $msg = $this->__('Sync paused for this store view. The queue is preserved; resume to continue.');
        Mage::getSingleton('adminhtml/session')->addSuccess($msg);
        if (!$this->_respondAjaxIfApplicable(true, $msg)) {
            $this->_redirect('adminhtml/system_config/edit', array('section' => 'mibizum_sync'));
        }
    }

    /** Resume sync for the current store view. */
    public function resumeAction()
    {
        if (!$this->_guardWrite()) {
            return;
        }
        $storeId = $this->_getStoreViewId();
        if (!$storeId) {
            $msg = $this->__('Select a store view to resume sync.');
            if (!$this->_respondAjaxIfApplicable(false, $msg)) {
                Mage::getSingleton('adminhtml/session')->addError($msg);
                $this->_redirect('adminhtml/system_config/edit', array('section' => 'mibizum_sync'));
            }
            return;
        }
        Mage::helper('mibizum_sync')->setPaused($storeId, false);
        $msg = $this->__('Sync resumed for this store view.');
        Mage::getSingleton('adminhtml/session')->addSuccess($msg);
        if (!$this->_respondAjaxIfApplicable(true, $msg)) {
            $this->_redirect('adminhtml/system_config/edit', array('section' => 'mibizum_sync'));
        }
    }

    /**
     * Resolve the store-view id from the request.
     *
     * @return int 0 if not in store-view scope.
     */
    protected function _getStoreViewId()
    {
        $storeId = (int) $this->getRequest()->getParam('store_id');
        if ($storeId > 0) {
            return $storeId;
        }
        $storeCode = (string) $this->getRequest()->getParam('store');
        if ($storeCode !== '') {
            try {
                return (int) Mage::app()->getStore($storeCode)->getId();
            } catch (Exception $e) {
                return 0;
            }
        }
        return 0;
    }

    /** JSON endpoint for the on-screen AJAX. */
    public function statsAction()
    {
        $stats = array();
        try {
            $stats['queue'] = Mage::getSingleton('mibizum_sync/indexer_queue')->getStats();
        } catch (Exception $e) {
            $stats['queue_error'] = $e->getMessage();
        }
        try {
            // getStats() ignores any index name (the Mibizum backend resolves the
            // index from the API key). The previous getSearchIndexName() helper was
            // removed in the multistore refactor; calling it threw and made the
            // Reindex panel / install-wizard show "0 in index" even when populated.
            $client = Mage::getModel('mibizum_sync/adapter_mibizum');
            $stats['index'] = $client->getStats();
        } catch (Exception $e) {
            $stats['index_error'] = $e->getMessage();
        }

        $this->getResponse()
            ->setHeader('Content-Type', 'application/json; charset=UTF-8', true)
            ->setBody(json_encode($stats, JSON_UNESCAPED_UNICODE));
    }
}
