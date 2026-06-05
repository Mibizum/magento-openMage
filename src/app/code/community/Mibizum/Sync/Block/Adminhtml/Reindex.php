<?php
/**
 * Block for the "Reindex" fieldset (inside General Configuration).
 *
 * Visually renders the index status (last reindex, indexed products, pending
 * queue) plus the action buttons. The raw status is only shown when debug mode
 * is active.
 *
 * Compatible with PHP 5.4+.
 */
class Mibizum_Sync_Block_Adminhtml_Reindex extends Mage_Adminhtml_Block_Template
{
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('mibizum_sync/reindex.phtml');
    }

    /**
     * URLs for the controller actions. The block is rendered embedded in
     * system_config (frontend_model), where relative URLs would resolve badly;
     * the inline block injects absolute URLs via setData('full_reindex_url',...).
     */
    public function getFullReindexUrl()
    {
        $override = $this->getData('full_reindex_url');
        return $override !== null && $override !== '' ? $override : $this->getUrl('*/*/full');
    }

    public function getDrainQueueUrl()
    {
        $override = $this->getData('drain_queue_url');
        return $override !== null && $override !== '' ? $override : $this->getUrl('*/*/drain');
    }

    public function getStatsUrl()
    {
        $override = $this->getData('stats_url');
        return $override !== null && $override !== '' ? $override : $this->getUrl('*/*/stats');
    }

    public function getProgressUrl()
    {
        $override = $this->getData('progress_url');
        return $override !== null && $override !== '' ? $override : $this->getUrl('*/*/progress');
    }

    /**
     * @return bool Debug mode (if active, the raw status is shown).
     */
    public function isDebugMode()
    {
        return Mage::helper('mibizum_sync')->isDebugMode();
    }

    /**
     * Store-view id passed by the Inline container from the config scope URL.
     * 0 means "not in store-view scope" (default/website).
     *
     * @return int
     */
    public function getCurrentStoreViewId()
    {
        $id = $this->getData('current_store_id');
        return $id ? (int) $id : 0;
    }

    /**
     * Whether the current store-view has its connection fully configured.
     *
     * @return bool
     */
    public function isCurrentStoreConfigured()
    {
        $storeId = $this->getCurrentStoreViewId();
        if (!$storeId) {
            return false;
        }
        return Mage::helper('mibizum_sync')->isEnabled($storeId);
    }

    /** @return bool */
    public function isPaused()
    {
        $storeId = $this->getCurrentStoreViewId();
        if (!$storeId) {
            return false;
        }
        return Mage::helper('mibizum_sync')->isPaused($storeId);
    }

    /** @return string */
    public function getPauseUrl()
    {
        $v = $this->getData('pause_url');
        return ($v !== null && $v !== '') ? $v : '';
    }

    /** @return string */
    public function getResumeUrl()
    {
        $v = $this->getData('resume_url');
        return ($v !== null && $v !== '') ? $v : '';
    }

    /**
     * Date and result of the last full reindex.
     *
     * @return array {at: string|null, at_label: string, status: string}
     */
    public function getLastReindex()
    {
        $at     = (string) Mage::getStoreConfig('mibizum_sync/reindex/last_full_at');
        $status = (string) Mage::getStoreConfig('mibizum_sync/reindex/last_full_status');
        $label  = '';
        if ($at !== '') {
            try {
                $label = Mage::helper('core')->formatDate(
                    $at, Mage_Core_Model_Locale::FORMAT_TYPE_MEDIUM, true
                );
            } catch (Exception $e) {
                $label = $at;
            }
        }
        return array(
            'at'       => $at !== '' ? $at : null,
            'at_label' => $label,
            'status'   => $status,
        );
    }

    /**
     * Status of the indexing queue.
     *
     * @return array {total, pending, locked, oldest_pending_seconds} or {error}
     */
    public function getQueueStats()
    {
        try {
            return Mage::getSingleton('mibizum_sync/indexer_queue')->getStats();
        } catch (Exception $e) {
            return array('error' => $e->getMessage());
        }
    }

    /**
     * Number of products in the search index. null if the service does not
     * respond, or if the adapter does not expose getStats / the helper does not
     * expose getSearchIndexName.
     *
     * Defensive port: in PHP 5.4 "undefined method" fatals are NOT catchable by
     * try/catch (only PHP 7+ Throwable). That is why we check method_exists
     * before calling, to avoid crashing the System Config admin render.
     *
     * @return int|null
     */
    public function getIndexDocCount()
    {
        try {
            $client = Mage::getModel('mibizum_sync/adapter_mibizum');
            $helper = Mage::helper('mibizum_sync');
            if (!is_object($client) || !method_exists($client, 'getStats')) return null;
            $indexName = method_exists($helper, 'getSearchIndexName')
                ? $helper->getSearchIndexName()
                : null;
            $stats = $client->getStats($indexName);
            return isset($stats['numberOfDocuments']) ? (int) $stats['numberOfDocuments'] : null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * True only when the install has more than one store-view (then the
     * multi-store overview below is worth showing; a single-store merchant sees
     * no clutter).
     *
     * @return bool
     */
    public function isMultiStore()
    {
        return count(Mage::app()->getStores()) > 1;
    }

    /**
     * Multi-store overview: one row per store-view with its Mibizum catalog
     * (data source slug) and connection status, so the merchant sees at a glance
     * which store-views are wired up and which need attention. Store-views that
     * resolve to the same destination (API key + URL + slug) share one catalog;
     * the count of DISTINCT connected catalogs is getConnectedCatalogCount().
     *
     * @return array[] each: {website, store_view, slug, status} where status is
     *                 'connected' | 'misconfigured' | 'disabled'
     */
    public function getStoreViewConnections()
    {
        /** @var Mibizum_Sync_Helper_Data $helper */
        $helper = Mage::helper('mibizum_sync');
        $rows = array();
        foreach (Mage::app()->getWebsites() as $website) {
            foreach ($website->getGroups() as $group) {
                foreach ($group->getStores() as $store) {
                    $svId = (int) $store->getId();
                    $slug = $helper->getDataSourceSlug($svId);
                    if ($helper->isEnabled($svId)) {
                        $status = 'connected';
                    } elseif (Mage::getStoreConfigFlag('mibizum_sync/connection/enabled', $svId)) {
                        $status = 'misconfigured'; // flag on but missing key/url
                    } else {
                        $status = 'disabled';
                    }
                    $rows[] = array(
                        'website'    => (string) $website->getName(),
                        'store_view' => (string) $store->getName(),
                        'slug'       => $slug !== null ? $slug : '',
                        'status'     => $status,
                    );
                }
            }
        }
        return $rows;
    }

    /**
     * Number of DISTINCT connected Mibizum catalogs (deduped by destination
     * signature among connected store-views).
     *
     * @return int
     */
    public function getConnectedCatalogCount()
    {
        /** @var Mibizum_Sync_Helper_Data $helper */
        $helper = Mage::helper('mibizum_sync');
        $sigs = array();
        foreach ($helper->getEnabledStoreViewIds() as $svId) {
            $sigs[$helper->getDestinationSignature($svId)] = true;
        }
        return count($sigs);
    }

    /**
     * True if at least one store-view has the connection flag on but is missing
     * its API key/URL (so it will NOT be indexed). Drives a warning banner.
     *
     * @return bool
     */
    public function hasMisconfiguredStoreView()
    {
        foreach ($this->getStoreViewConnections() as $r) {
            if ($r['status'] === 'misconfigured') {
                return true;
            }
        }
        return false;
    }

    /**
     * Human label for a connection status (translated).
     *
     * @param string $status
     * @return string
     */
    public function getStatusLabel($status)
    {
        switch ($status) {
            case 'connected':     return $this->__('Connected');
            case 'misconfigured': return $this->__('Not configured');
            default:              return $this->__('Disabled');
        }
    }
}
