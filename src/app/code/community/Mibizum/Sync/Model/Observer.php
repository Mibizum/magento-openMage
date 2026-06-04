<?php
/**
 * Mibizum_Sync_Model_Observer
 *
 * Magento event hooks that keep the search index in sync with catalog changes.
 * Reindex operations are queued (not run inline) so saving in the admin is
 * never slowed down.
 *
 * Subscribed events (see config.xml):
 *  - catalog_product_save_after             -> reindex the product.
 *  - catalog_product_delete_before          -> remove it from the index.
 *  - cataloginventory_stock_item_save_after -> reindex (stock changed).
 *
 * SAFE-DISABLE: every catalog observer short-circuits early when the module is
 * not connected at ANY scope (`isEnabledAnywhere()` = the default scope OR any
 * store-view has `connection/enabled` + api key + api url). Multi-store: a
 * merchant may connect only some store-views; as long as one is connected the
 * change is enqueued and the worker fans it out to the relevant catalogs.
 * Without any connection there is nowhere to sync to, so enqueuing would only
 * fill the `mibizum_sync_index_queue` table for nothing. This matches what the
 * admin screen promises ("when it is off, observers do NOT enqueue") and avoids
 * touching the database on a store where the module is installed but not yet
 * configured.
 *
 * Mind the two separate flags:
 *   - `connection/enabled` (what `isEnabled()` checks): the master switch for
 *     SYNCING. Off = the module neither enqueues nor drains.
 *   - `general/enabled`: the storefront WIDGET switch (the JS overlay). It does
 *     NOT affect indexing: with the connection active the catalog stays fresh
 *     even while the widget is hidden, so the day the storefront widget is
 *     turned on the data is already up to date and the dashboard has history.
 *
 * Compatible with PHP 5.4 through 8.x.
 */
class Mibizum_Sync_Model_Observer
{
    /**
     * controller_front_init_routers: register the clean-URL router for the
     * Smart Item (ingredient) ficha `/{url_prefix}/{slug}`. Defensive: never
     * breaks frontend init if anything is off (the router itself is also a
     * no-op when the ficha feature is disabled).
     *
     * @param Varien_Event_Observer $observer
     */
    public function initControllerRouters(Varien_Event_Observer $observer)
    {
        try {
            $front = $observer->getEvent()->getFront();
            if ($front) {
                $front->addRouter('mibizum_sync_ingredient', new Mibizum_Sync_Controller_IngredientRouter());
            }
        } catch (Exception $e) {
            // Never break the storefront because of the ingredient router.
        }
    }

    /**
     * Product saved: enqueue its reindex.
     *
     * @param Varien_Event_Observer $observer
     */
    public function onProductSaveAfter(Varien_Event_Observer $observer)
    {
        if (!Mage::helper('mibizum_sync')->isEnabledAnywhere()) {
            return;
        }

        try {
            $product = $observer->getEvent()->getProduct();
            if (!$product || !$product->getId()) {
                return;
            }

            Mage::getSingleton('mibizum_sync/indexer_queue')->enqueueProductUpdate(
                (int) $product->getId(),
                'product_save'
            );
        } catch (Exception $e) {
            Mage::helper('mibizum_sync')->log(
                'onProductSaveAfter failed: ' . $e->getMessage(),
                Zend_Log::ERR
            );
        }
    }

    /**
     * Product about to be deleted: enqueue its removal from the index.
     *
     * @param Varien_Event_Observer $observer
     */
    public function onProductDeleteBefore(Varien_Event_Observer $observer)
    {
        if (!Mage::helper('mibizum_sync')->isEnabledAnywhere()) {
            return;
        }

        try {
            $product = $observer->getEvent()->getProduct();
            if (!$product || !$product->getId()) {
                return;
            }

            // Capture the SKU now, while the product still exists: documents are
            // keyed by SKU and the product is gone by the time the queue drains.
            Mage::getSingleton('mibizum_sync/indexer_queue')->enqueueProductDelete(
                (int) $product->getId(),
                (string) $product->getSku(),
                'product_delete'
            );
        } catch (Exception $e) {
            Mage::helper('mibizum_sync')->log(
                'onProductDeleteBefore failed: ' . $e->getMessage(),
                Zend_Log::ERR
            );
        }
    }

    /**
     * Stock change: enqueue a reindex of the affected product.
     *
     * @param Varien_Event_Observer $observer
     */
    public function onStockChange(Varien_Event_Observer $observer)
    {
        if (!Mage::helper('mibizum_sync')->isEnabledAnywhere()) {
            return;
        }

        try {
            $stockItem = $observer->getEvent()->getItem();
            if (!$stockItem) {
                return;
            }
            $productId = (int) $stockItem->getProductId();
            if ($productId <= 0) {
                return;
            }

            Mage::getSingleton('mibizum_sync/indexer_queue')->enqueueProductUpdate(
                $productId,
                'stock_change'
            );
        } catch (Exception $e) {
            Mage::helper('mibizum_sync')->log(
                'onStockChange failed: ' . $e->getMessage(),
                Zend_Log::ERR
            );
        }
    }

    /**
     * After the "Mibizum Sync" section is saved in the admin System Config,
     * capture the values just persisted to core_config_data and log them as a
     * signal of the change.
     *
     * Pushing these settings UP to the SaaS over the tenant API requires the
     * backend to accept the indexer key with a wider scope for settings
     * mutations (today the tenant API only accepts a panel session token).
     * Until then the fields live in core_config_data and are read by
     * Helper::getStoreConfig at runtime, so the merchant sees and edits them
     * straight from the Magento admin. The PATCH call below is left commented
     * for that future phase.
     */
    public function onAdminConfigSaved(Varien_Event_Observer $observer)
    {
        $helper = Mage::helper('mibizum_sync');
        try {
            $snapshot = array(
                'general/enabled'              => (bool) Mage::getStoreConfig('mibizum_sync/general/enabled'),
                'general/feature_flag_param'   => (string) Mage::getStoreConfig('mibizum_sync/general/feature_flag_param'),
                'frontend/show_price'          => (bool) Mage::getStoreConfig('mibizum_sync/frontend/show_price'),
                'frontend/results_limit'       => (int)  Mage::getStoreConfig('mibizum_sync/frontend/results_limit'),
                'testing/mode'                 => (bool) Mage::getStoreConfig('mibizum_sync/testing/mode'),
                'testing/ip_whitelist_count'   => count($this->_normalizeIpWhitelist(
                    (string) Mage::getStoreConfig('mibizum_sync/testing/ip_whitelist')
                )),
            );
            $helper->log(
                'onAdminConfigSaved snapshot: ' . json_encode($snapshot)
                    . ' (SaaS push pending backend support)',
                Zend_Log::INFO
            );

            // Reindex when the CONNECTION TOPOLOGY changed: a store-view was
            // connected, disconnected, or repointed to a different catalog
            // (API key / URL / slug). A fingerprint comparison means we do NOT
            // reindex on unrelated setting saves (price toggle, overlay widths...).
            $fingerprint = $helper->getConnectionFingerprint();
            $stored      = (string) Mage::getStoreConfig('mibizum_sync/sync/last_connection_fingerprint');
            if ($fingerprint !== $stored) {
                Mage::getConfig()->saveConfig('mibizum_sync/sync/last_connection_fingerprint', $fingerprint);
                Mage::app()->getCacheInstance()->cleanType('config');
                if ($helper->isEnabledAnywhere()) {
                    $enqueued = $helper->enqueueAllProductsForReindex('connection_changed');
                    $helper->log(
                        'connection topology changed -> enqueued ' . (int) $enqueued . ' products for reindex',
                        Zend_Log::INFO
                    );
                    if ($enqueued > 0) {
                        Mage::getSingleton('adminhtml/session')->addNotice(
                            $helper->__('%d products pending update in search.', $enqueued)
                        );
                    }
                }
            }
            /*
            // Enable once the backend accepts the indexer key on the tenant API:
            $slug = $helper->getDataSourceSlug() ?: 'productos';
            $adapter = Mage::getSingleton('mibizum_sync/adapter_mibizum');
            $adapter->patchAuthenticated(
                rtrim($helper->getApiUrl(), '/') . '/api/tenant/data-sources/' . urlencode($slug) . '/settings',
                array(
                    'testingMode'       => $snapshot['testing/mode'],
                    'testingAllowedIps' => $this->_normalizeIpWhitelist(
                        (string) Mage::getStoreConfig('mibizum_sync/testing/ip_whitelist')
                    ),
                )
            );
            */
        } catch (Exception $e) {
            $helper->log('onAdminConfigSaved failed: ' . $e->getMessage(), Zend_Log::WARN);
        }
    }

    /**
     * Normalize a textarea of IPs separated by commas/spaces/newlines into an
     * indexed array of unique, non-empty IP strings. Accepts IPv4 and IPv6.
     */
    protected function _normalizeIpWhitelist($raw)
    {
        if (!$raw) return array();
        $parts = preg_split('/[\s,]+/', trim($raw));
        $out = array();
        foreach ($parts as $ip) {
            $ip = trim($ip);
            if ($ip !== '' && !in_array($ip, $out, true)) {
                $out[] = $ip;
            }
        }
        return $out;
    }

}
