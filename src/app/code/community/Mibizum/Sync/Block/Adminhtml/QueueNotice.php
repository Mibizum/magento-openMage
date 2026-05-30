<?php
/**
 * Persistent global banner: warns about pending changes in the index.
 *
 * It is injected into ALL admin screens via the adminhtml layout (handle
 * <default>). If the queue is empty it renders nothing. If there are pending
 * items, it shows a top strip with the counter and a button that triggers the
 * drain in the background (same endpoint as the Reindex fieldset's).
 *
 * Zero hardcoding: the counter comes from
 * Mibizum_Sync_Model_Indexer_Queue::getStats(), and the drain URL is built
 * with getUrl(). Error-tolerant: if the helper breaks or the ACL does not
 * allow drain, it does not break the admin.
 *
 * Compatible with PHP 5.4+.
 */
class Mibizum_Sync_Block_Adminhtml_QueueNotice extends Mage_Adminhtml_Block_Template
{
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('mibizum_sync/queue_notice.phtml');
    }

    /**
     * @return int Products pending to be updated in the index.
     */
    public function getPending()
    {
        try {
            $stats = Mage::getSingleton('mibizum_sync/indexer_queue')->getStats();
            return isset($stats['pending']) ? (int) $stats['pending'] : 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * @return int Locked products (currently being processed by the cron).
     */
    public function getLocked()
    {
        try {
            $stats = Mage::getSingleton('mibizum_sync/indexer_queue')->getStats();
            return isset($stats['locked']) ? (int) $stats['locked'] : 0;
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * @return string Absolute URL to the endpoint that drains the queue.
     */
    public function getDrainUrl()
    {
        return $this->getUrl('adminhtml/mibizum_sync_reindex/drain');
    }

    /**
     * @return string CSRF token of the current admin (needed by the drain POST).
     */
    public function getFormKey()
    {
        return Mage::getSingleton('core/session')->getFormKey();
    }

    /**
     * Do not render the banner if:
     *  - The queue is empty (nothing to warn about).
     *  - The user has no ACL for the drain (they could do nothing with the notice).
     *  - We are on the Reindex screen (there is already a dedicated fieldset;
     *    duplicating it is just noise).
     */
    protected function _toHtml()
    {
        if ($this->getPending() <= 0) {
            return '';
        }
        if (!Mage::getSingleton('admin/session')->isAllowed('mibizum_sync/search/reindex')) {
            return '';
        }
        $req = $this->getRequest();
        if ($req->getModuleName() === 'adminhtml'
            && $req->getControllerName() === 'mibizum_sync_reindex') {
            return '';
        }
        return parent::_toHtml();
    }
}
