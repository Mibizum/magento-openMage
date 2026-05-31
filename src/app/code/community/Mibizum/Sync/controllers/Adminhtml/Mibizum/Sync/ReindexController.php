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

    /** Enqueue all products and drain the queue. Blocking (may take a while). */
    public function fullAction()
    {
        if (!$this->_guardWrite()) {
            return;
        }
        $ok  = false;
        $msg = '';
        try {
            Mage::getModel('mibizum_sync/scheduler')->fullReindex('manual');
            $ok  = true;
            $msg = $this->__('Full reindex launched. Check the mibizum_sync.log log for details.');
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
