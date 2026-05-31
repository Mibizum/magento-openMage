<?php
/**
 * Mibizum_Sync_Helper_Data
 *
 * The module's base helper. Magento requires every module to have a Data helper
 * to resolve translations via Mage::helper('mibizum_sync')->__('...').
 *
 * Centralizes access to configuration (system.xml) so the rest of the module
 * does not scatter Mage::getStoreConfig() calls everywhere.
 *
 * What it provides:
 *   - Config getters (isEnabled, getApiUrl, getApiKey, getDataSourceSlug).
 *   - The master badge toggles.
 *   - Badge indexes (Nature, Attribute) - the module computes and publishes them
 *     in the document, while the Mibizum panel controls the visual style.
 *   - reportSyncRun (best-effort POST to the panel with reindex stats).
 *   - log + enqueueProductsForBadge + enqueueAllProductsForReindex.
 *   - getCategoryPickerMarkup + loadCategoryAssignmentsForBadge (admin UI).
 *
 * Compatible with PHP 5.4 through 8.x.
 */
class Mibizum_Sync_Helper_Data extends Mage_Core_Helper_Abstract
{
    const XML_PATH_ENABLED            = 'mibizum_sync/connection/enabled';
    const XML_PATH_API_URL            = 'mibizum_sync/connection/api_url';
    const XML_PATH_API_KEY            = 'mibizum_sync/connection/api_key';
    const XML_PATH_SEARCH_API_KEY     = 'mibizum_sync/connection/search_api_key';
    const XML_PATH_DATA_SOURCE_SLUG   = 'mibizum_sync/connection/data_source_slug';
    const XML_PATH_DEBUG_MODE         = 'mibizum_sync/connection/debug_mode';
    const XML_PATH_WIDGET_SNIPPET     = 'mibizum_sync/frontend/widget_snippet';
    const XML_PATH_BATCH_SIZE         = 'mibizum_sync/sync/batch_size';
    const XML_PATH_MAX_ATTEMPTS       = 'mibizum_sync/sync/max_attempts';
    const XML_PATH_TIMEOUT_SECONDS    = 'mibizum_sync/sync/timeout_seconds';
    const XML_PATH_BADGES_SHOW_NATURES    = 'mibizum_sync_badges/types/show_natures';
    const XML_PATH_BADGES_SHOW_ATTRIBUTES = 'mibizum_sync_badges/types/show_attributes';
    const XML_PATH_BADGES_SHOW_SYSTEM     = 'mibizum_sync_badges/types/show_system';
    const XML_PATH_GENERAL_ENABLED        = 'mibizum_sync/general/enabled';
    const XML_PATH_WIZARD_STATE           = 'mibizum_sync/wizard/state';

    /** First-run install-wizard states (see Block_Adminhtml_Wizard). */
    const WIZARD_PENDING   = 'pending';
    const WIZARD_DISMISSED = 'dismissed';
    const WIZARD_DONE      = 'done';

    /** @var bool|null Per-request cache for isEnabledAnywhere(). */
    protected $_enabledAnywhere = null;

    /**
     * The module is active and minimally configured. Every observer/cron asks
     * this before doing anything - an unconfigured module must NOT enqueue or
     * try to publish.
     */
    public function isEnabled($store = null)
    {
        if (!Mage::getStoreConfigFlag(self::XML_PATH_ENABLED, $store)) {
            return false;
        }
        $apiKey = $this->getApiKey($store);
        $apiUrl = $this->getApiUrl($store);
        return !empty($apiKey) && !empty($apiUrl);
    }

    /**
     * The server-side search path is usable: the connection master switch is on
     * and the SEARCH key + API URL are set. Unlike isEnabled() it does NOT
     * require the indexer key, so a merchant can route the Enter
     * (/catalogsearch/result/) through the engine WITHOUT indexing the catalog
     * from Magento (e.g. the catalog is populated by another source). The Enter
     * override (NativeSearchBridge) gates on this.
     *
     * @param int|null $store
     * @return bool
     */
    public function isSearchEnabled($store = null)
    {
        if (!Mage::getStoreConfigFlag(self::XML_PATH_ENABLED, $store)) {
            return false;
        }
        $key = $this->getSearchApiKey($store);
        $url = $this->getApiUrl($store);
        return !empty($key) && !empty($url);
    }

    /**
     * True if the module is connected at the default scope OR at any store-view.
     * Observers use this (a merchant may enable the module only per store-view,
     * leaving the default scope disabled), so changes still get enqueued.
     * Cached per request to stay cheap during bulk catalog saves/imports.
     *
     * @return bool
     */
    public function isEnabledAnywhere()
    {
        if ($this->_enabledAnywhere !== null) {
            return $this->_enabledAnywhere;
        }
        if ($this->isEnabled()) {
            $this->_enabledAnywhere = true;
        } else {
            $this->_enabledAnywhere = count($this->getEnabledStoreViewIds()) > 0;
        }
        return $this->_enabledAnywhere;
    }

    public function getApiUrl($store = null)
    {
        $url = (string) Mage::getStoreConfig(self::XML_PATH_API_URL, $store);
        $url = rtrim($url, '/');
        return $url !== '' ? $url : 'https://app.mibizum.io';
    }

    /**
     * The widget JS snippet the merchant pastes from the Mibizum panel
     * (Domains -> JS code). The module injects it VERBATIM in the frontend
     * <head> (it does not build the <script> itself). Empty = nothing injected.
     *
     * The snippet is the evergreen loader (points to /sdk/v1.js, which
     * auto-updates within the 1.x major) or, if the merchant prefers, a pinned
     * version (/sdk/v1.x.y.js). The module is agnostic: it only emits what was
     * pasted.
     *
     * @return string  '' if no snippet is configured
     */
    public function getWidgetSnippet()
    {
        return trim((string) Mage::getStoreConfig(self::XML_PATH_WIDGET_SNIPPET));
    }

    /**
     * Tenant API key - scope `indexer`. Encrypted in the DB via the obscure
     * backend `adminhtml/system_config_backend_encrypted` (see system.xml).
     *
     * **Magento 1.x quirk**: when saved via the admin FORM, the backend_model
     * encrypts before persisting, and `getStoreConfig()` applies the decrypt
     * automatically on read. BUT if config is saved programmatically via
     * `saveConfig()` (CLI / install scripts), it does NOT go through the
     * backend_model - the caller must encrypt beforehand AND getStoreConfig
     * returns the raw encrypted value.
     *
     * That is why we distinguish here: if the incoming value already looks like
     * a Mibizum API key in cleartext (`mbz_live_...` or `mbz_test_...`), we
     * return it as-is. Otherwise we assume it is encrypted and decrypt it.
     */
    public function getApiKey($store = null)
    {
        $raw = (string) Mage::getStoreConfig(self::XML_PATH_API_KEY, $store);
        if ($raw === '') return '';

        // Already cleartext (rare but possible if the admin saved it plain by mistake)
        if (preg_match('/^mbz_(live|test)_/', $raw)) {
            return $raw;
        }

        // Encrypted: decrypt. If it fails (wrong/corrupted key) we return an
        // empty string instead of an exception - isEnabled() will treat it as
        // "not configured".
        try {
            return (string) Mage::helper('core')->decrypt($raw);
        } catch (Exception $e) {
            return '';
        }
    }

    /**
     * Search API key (scope=search) - for the server-side Enter adapter
     * (Mibizum_Sync_Model_Search_Adapter). Separate from getApiKey(), which is
     * the indexer one (scope=indexer). Each scope lives in a distinct key.
     */
    public function getSearchApiKey($store = null)
    {
        $raw = (string) Mage::getStoreConfig(self::XML_PATH_SEARCH_API_KEY, $store);
        if ($raw === '') return '';
        if (preg_match('/^mbz_(live|test)_/', $raw)) return $raw;
        try {
            return (string) Mage::helper('core')->decrypt($raw);
        } catch (Exception $e) {
            return '';
        }
    }

    /**
     * Engine runtime params (rankingScoreThreshold, matchingStrategy,
     * debounceMs, minQueryLen, cacheTtlSeconds). Consumed by the Enter adapter
     * to build engine queries consistent with what the merchant configured in
     * their Mibizum panel (a single engine = the same threshold for the overlay
     * and the Enter).
     *
     * Local 60s cache to avoid curls on every page render. If the SaaS is down
     * we return defaults (we do not break the store - at least the Enter keeps
     * working).
     */
    public function getSearchEngineParams()
    {
        static $cache = null;
        static $cacheTs = 0;
        if ($cache !== null && (time() - $cacheTs) < 60) {
            return $cache;
        }

        $defaults = array(
            'rankingScoreThreshold' => 0.5,
            'matchingStrategy'      => 'all',
            'debounceMs'            => 250,
            'minQueryLen'           => 2,
            'cacheTtlSeconds'       => 60,
        );

        $apiUrl = $this->getApiUrl();
        $slug   = $this->getDataSourceSlug();
        $host   = parse_url(Mage::getBaseUrl(), PHP_URL_HOST);
        if (!$apiUrl || !$slug || !$host) {
            $cache = $defaults; $cacheTs = time();
            return $cache;
        }

        try {
            $url = rtrim($apiUrl, '/') . '/api/v1/runtime-config?' . http_build_query(array('source' => $slug));
            $ch = curl_init();
            curl_setopt_array($ch, array(
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_HTTPHEADER     => array(
                    'Origin: https://' . $host,
                    'Accept: application/json',
                ),
            ));
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code === 200 && $body) {
                $json = json_decode((string) $body, true);
                if (is_array($json) && isset($json['settings'])) {
                    $cache = array_merge($defaults, $json['settings']);
                    $cacheTs = time();
                    return $cache;
                }
            }
        } catch (Exception $e) {
            // Silent. Defaults below.
        }

        $cache = $defaults; $cacheTs = time();
        return $cache;
    }

    /**
     * Data source slug in the Mibizum tenant. Optional - if empty, the backend
     * uses the tenant's first dataSource.
     */
    public function getDataSourceSlug($store = null)
    {
        $slug = trim((string) Mage::getStoreConfig(self::XML_PATH_DATA_SOURCE_SLUG, $store));
        return $slug !== '' ? $slug : null;
    }

    // -------------------------------------------------------------------------
    // INSTALL WIZARD (first-run onboarding) - see Block_Adminhtml_Wizard
    // -------------------------------------------------------------------------

    /**
     * Current first-run wizard state. Empty/missing is treated as `pending`
     * (a fresh, unconfigured store). The block decides whether to show the
     * overlay (active states), a resume banner (dismissed) or nothing
     * (done, or the store is already connected).
     *
     * @return string
     */
    public function getWizardState()
    {
        $s = trim((string) Mage::getStoreConfig(self::XML_PATH_WIZARD_STATE));
        return $s !== '' ? $s : self::WIZARD_PENDING;
    }

    /**
     * Persist the wizard state at the default scope and flush the config cache
     * so the next request sees it. Called only from the admin WizardController
     * (ACL + form_key gated).
     *
     * @param string $state
     * @return $this
     */
    public function setWizardState($state)
    {
        Mage::getConfig()->saveConfig(self::XML_PATH_WIZARD_STATE, (string) $state, 'default', 0);
        try {
            Mage::app()->getCacheInstance()->cleanType('config');
        } catch (Exception $e) {
            // Cache flush is best-effort; the value is persisted regardless.
        }
        return $this;
    }

    /**
     * The wizard should never resurface once the merchant finished it or
     * dismissed it for good.
     *
     * @return bool
     */
    public function isWizardClosed()
    {
        $s = $this->getWizardState();
        return $s === self::WIZARD_DONE || $s === self::WIZARD_DISMISSED;
    }

    /**
     * Origin (scheme://host[:port]) of the Mibizum SaaS, derived from the
     * configured API URL. The wizard's parent JS accepts postMessage events
     * ONLY from this exact origin (the iframe -> module key hand-off).
     *
     * @return string '' if the API URL cannot be parsed
     */
    public function getMibizumOrigin()
    {
        $url = $this->getApiUrl();
        $p = @parse_url($url);
        if (!$p || empty($p['scheme']) || empty($p['host'])) {
            return '';
        }
        $origin = $p['scheme'] . '://' . $p['host'];
        if (!empty($p['port'])) {
            $origin .= ':' . $p['port'];
        }
        return $origin;
    }

    /**
     * Store-views (excluding the admin store) where the module is enabled at
     * that scope. These are the multi-store fan-out targets: the indexer
     * publishes each product into every connected store-view's catalog.
     *
     * A single-store merchant who configured the module only at "Default Config"
     * still gets every store-view here (they inherit the default scope); the
     * destination dedupe in the worker collapses them to a single catalog, so
     * behavior stays correct for them.
     *
     * @return int[] store-view ids
     */
    public function getEnabledStoreViewIds()
    {
        $ids = array();
        foreach (Mage::app()->getStores() as $store) {
            $svId = (int) $store->getId();
            if ($this->isEnabled($svId)) {
                $ids[] = $svId;
            }
        }
        return $ids;
    }

    /**
     * Stable signature of the indexing destination for a store-view: API URL +
     * API key + data-source slug. Two store-views with the same signature
     * publish to the SAME Mibizum catalog, so a product only needs to be sent
     * once for them. Used by the worker to dedupe fan-out destinations.
     *
     * @param int|Mage_Core_Model_Store $store
     * @return string
     */
    public function getDestinationSignature($store)
    {
        return sha1(
            $this->getApiUrl($store) . '|'
            . $this->getApiKey($store) . '|'
            . (string) $this->getDataSourceSlug($store)
        );
    }

    /**
     * Fingerprint of the whole connection topology: for every store-view, its
     * effective destination signature when connected, or 'off' when not. Lets
     * the admin-config-saved observer detect when a store-view was connected,
     * disconnected, or repointed to a different catalog (API key / URL / slug)
     * and trigger a reindex ONLY then (not on every unrelated setting save).
     *
     * @return string
     */
    public function getConnectionFingerprint()
    {
        $parts = array();
        foreach (Mage::app()->getStores() as $store) {
            $svId = (int) $store->getId();
            $parts[$svId] = $this->isEnabled($svId) ? $this->getDestinationSignature($svId) : 'off';
        }
        ksort($parts);
        return sha1(json_encode($parts));
    }

    public function isDebugMode()
    {
        return (bool) Mage::getStoreConfigFlag(self::XML_PATH_DEBUG_MODE);
    }

    public function getBatchSize()
    {
        $n = (int) Mage::getStoreConfig(self::XML_PATH_BATCH_SIZE);
        if ($n < 1)   $n = 100;
        if ($n > 500) $n = 500;
        return $n;
    }

    public function getMaxAttempts()
    {
        $n = (int) Mage::getStoreConfig(self::XML_PATH_MAX_ATTEMPTS);
        if ($n < 1) $n = 10;
        return $n;
    }

    public function getTimeoutSeconds()
    {
        $n = (int) Mage::getStoreConfig(self::XML_PATH_TIMEOUT_SECONDS);
        if ($n < 1) $n = 15;
        return $n;
    }

    // -------------------------------------------------------------------------
    // BADGES - 3 types: Natures, Attribute, System
    // -------------------------------------------------------------------------

    public function showNatureBadges()
    {
        return (bool) Mage::getStoreConfigFlag(self::XML_PATH_BADGES_SHOW_NATURES);
    }

    public function showAttributeBadges()
    {
        return (bool) Mage::getStoreConfigFlag(self::XML_PATH_BADGES_SHOW_ATTRIBUTES);
    }

    public function showSystemBadges()
    {
        return (bool) Mage::getStoreConfigFlag(self::XML_PATH_BADGES_SHOW_SYSTEM);
    }

    /**
     * System badge config (out_of_stock, in_offer, low_stock, new, featured).
     * The ProductMapper uses it to decide which ones to publish in the document.
     */
    public function getBadgesConfig()
    {
        $cfg = Mage::getStoreConfig('mibizum_sync/badges');
        if (!is_array($cfg)) $cfg = array();
        $masterOn = $this->showSystemBadges();
        $enabled  = function ($key, $default) use ($cfg, $masterOn) {
            if (!$masterOn) return false;
            return (bool) (isset($cfg[$key]) && $cfg[$key] !== '' ? $cfg[$key] : $default);
        };
        $get = function ($key, $default = '') use ($cfg) {
            return isset($cfg[$key]) && $cfg[$key] !== '' ? $cfg[$key] : $default;
        };
        return array(
            'out_of_stock' => array(
                'enabled' => $enabled('out_of_stock_enabled', 1),
                'label'   => (string) $get('out_of_stock_label', $this->__('Out of stock')),
            ),
            'in_offer' => array(
                'enabled' => $enabled('in_offer_enabled', 1),
                'label'   => (string) $get('in_offer_label', $this->__('On sale')),
            ),
            'low_stock' => array(
                'enabled'   => $enabled('low_stock_enabled', 1),
                'label'     => (string) $get('low_stock_label', $this->__('Last units')),
                'threshold' => (int)  $get('low_stock_threshold', 5),
            ),
            'new' => array(
                'enabled' => $enabled('new_enabled', 0),
                'label'   => (string) $get('new_label', $this->__('New')),
                'days'    => (int)  $get('new_days', 30),
            ),
            'featured' => array(
                'enabled' => $enabled('featured_enabled', 0),
                'label'   => (string) $get('featured_label', $this->__('Featured')),
            ),
        );
    }

    /**
     * Index of nature badges by category_id, expanding descendants via path
     * LIKE. Returns a dict {category_id => badge_data} with the WINNING badge
     * per category (lowest sort_priority).
     */
    public function getNatureBadgesIndex()
    {
        if (!$this->showNatureBadges()) return array();
        static $cached = null;
        if ($cached !== null) return $cached;

        $resource = Mage::getSingleton('core/resource');
        $read     = $resource->getConnection('core_read');

        $natTable       = $resource->getTableName('mibizum_sync/nature');
        $bridgeTable    = $resource->getTableName('mibizum_sync/natureCategory');
        $catEntityTable = $resource->getTableName('catalog/category');

        // SAFE-DISABLE: never assume the tables exist. If the module's tables are
        // missing (freshly uploaded without setup, or a partial uninstall) we
        // return an empty index instead of propagating the SQL error to the doc
        // render.
        try {
            $rows = $read->fetchAll(
                "SELECT n.id, n.label, n.slug, n.icon_svg, n.icon_url, n.icon_fa_class,
                        n.color_hex, n.text_color_hex,
                        n.display_mode, n.position, n.shape, n.sort_priority,
                        nc.category_id, nc.include_descendants
                 FROM $natTable n
                 JOIN $bridgeTable nc ON nc.badge_id = n.id
                 WHERE n.enabled = 1
                 ORDER BY n.sort_priority ASC, n.id ASC"
            );
        } catch (Exception $e) {
            $this->log('getNatureBadgesIndex query failed (missing tables?): ' . $e->getMessage(), Zend_Log::WARN);
            $cached = array();
            return $cached;
        }
        if (empty($rows)) { $cached = array(); return $cached; }

        $catToBadge = array();
        $badgeCache = array();

        foreach ($rows as $r) {
            $badgeId = (int) $r['id'];

            if (!isset($badgeCache[$badgeId])) {
                $textColor = $r['text_color_hex'];
                if ($textColor === null || $textColor === '') {
                    $textColor = Mibizum_Sync_Model_Nature::contrastingTextColor($r['color_hex']);
                }
                $badgeCache[$badgeId] = array(
                    'id'              => $badgeId,
                    'slug'            => (string) $r['slug'],
                    'label'           => (string) $r['label'],
                    'color_hex'       => (string) $r['color_hex'],
                    'text_color_hex'  => (string) $textColor,
                    'icon_svg'        => ($r['icon_svg'] !== null && $r['icon_svg'] !== '') ? (string) $r['icon_svg'] : null,
                    'icon_url'        => ($r['icon_url'] !== null && $r['icon_url'] !== '') ? (string) $r['icon_url'] : null,
                    'icon_fa_class'   => (isset($r['icon_fa_class']) && $r['icon_fa_class'] !== null && $r['icon_fa_class'] !== '') ? (string) $r['icon_fa_class'] : null,
                    'display_mode'    => (string) $r['display_mode'],
                    'position'        => (string) $r['position'],
                    'shape'           => (string) $r['shape'],
                    'sort_priority'   => (int) $r['sort_priority'],
                );
            }
            $badge = $badgeCache[$badgeId];

            $cid = (int) $r['category_id'];
            $catIds = array($cid);

            if (!empty($r['include_descendants'])) {
                try {
                    $descendants = $read->fetchCol(
                        "SELECT entity_id FROM $catEntityTable
                         WHERE path LIKE :p1 OR path LIKE :p2",
                        array(':p1' => '%/' . $cid . '/%', ':p2' => '%/' . $cid)
                    );
                    foreach ($descendants as $d) $catIds[] = (int) $d;
                } catch (Exception $e) {
                    $this->log('getNatureBadgesIndex expand descendants failed: ' . $e->getMessage(), Zend_Log::WARN);
                }
            }

            foreach (array_unique($catIds) as $cidExpanded) {
                if (!isset($catToBadge[$cidExpanded])) {
                    $catToBadge[$cidExpanded] = $badge;
                }
            }
        }

        $cached = $catToBadge;
        return $cached;
    }

    /**
     * List of active attribute badges. The ProductMapper evaluates them against
     * each product and publishes the matching ones in the document.
     */
    public function getAttributeBadgesList()
    {
        if (!$this->showAttributeBadges()) return array();
        static $cached = null;
        if ($cached !== null) return $cached;

        try {
            $coll = Mage::getModel('mibizum_sync/attributeBadge')->getCollection()
                ->addFieldToFilter('enabled', 1);
        } catch (Exception $e) {
            $cached = array();
            return $cached;
        }

        $catFilter = $this->_buildAttributeBadgeCategoryFilter();

        $out = array();
        foreach ($coll as $b) {
            $textColor = $b->getTextColorHex();
            if ($textColor === null || $textColor === '') {
                $textColor = Mibizum_Sync_Model_Nature::contrastingTextColor($b->getColorHex());
            }
            $bid = (int) $b->getId();
            $out[] = array(
                'id'              => $bid,
                'attribute_code'  => (string) $b->getAttributeCode(),
                'label_fallback'  => $b->getLabel() ? (string) $b->getLabel() : null,
                'color_hex'       => (string) $b->getColorHex(),
                'text_color_hex'  => (string) $textColor,
                'icon_svg'        => $b->getIconSvg() ? (string) $b->getIconSvg() : null,
                'icon_url'        => $b->getIconUrl() ? (string) $b->getIconUrl() : null,
                'icon_fa_class'   => $b->getIconFaClass() ? (string) $b->getIconFaClass() : null,
                'position'        => (string) $b->getPosition(),
                'shape'           => (string) $b->getShape(),
                'display_mode'    => (string) $b->getDisplayMode(),
                'sort_priority'   => (int) $b->getSortPriority(),
                'category_filter' => isset($catFilter[$bid]) ? $catFilter[$bid] : null,
            );
        }
        $cached = $out;
        return $cached;
    }

    protected function _buildAttributeBadgeCategoryFilter()
    {
        $resource = Mage::getSingleton('core/resource');
        $read = $resource->getConnection('core_read');
        $bridgeTable = $resource->getTableName('mibizum_sync/attributeBadgeCategory');
        $catTable    = $resource->getTableName('catalog/category');

        try {
            $rows = $read->fetchAll("SELECT badge_id, category_id, include_descendants FROM $bridgeTable");
        } catch (Exception $e) {
            return array();
        }
        if (empty($rows)) return array();

        $out = array();
        foreach ($rows as $r) {
            $bid = (int) $r['badge_id'];
            $cid = (int) $r['category_id'];
            if (!isset($out[$bid])) $out[$bid] = array();
            $out[$bid][$cid] = true;

            if ((int) $r['include_descendants'] === 1) {
                try {
                    $cat = Mage::getModel('catalog/category')->load($cid);
                    if ($cat && $cat->getId()) {
                        $path = (string) $cat->getPath();
                        $descendants = $read->fetchCol(
                            "SELECT entity_id FROM $catTable WHERE path LIKE ? AND entity_id != ?",
                            array($path . '/%', $cid)
                        );
                        foreach ($descendants as $did) {
                            $out[$bid][(int) $did] = true;
                        }
                    }
                } catch (Exception $e) {
                    Mage::log('attributeBadgeCategory expand descendants failed: ' . $e->getMessage(), Zend_Log::WARN, 'mibizum_sync.log');
                }
            }
        }

        foreach ($out as $bid => $cidsMap) {
            $out[$bid] = array_keys($cidsMap);
        }
        return $out;
    }

    /** No-op today. Future hook if it moves to Mage::app()->getCache(). */
    public function resetNatureBadgesIndex() {}

    /**
     * Enqueue for reindex all products affected by the categories assigned to a
     * badge (with descendants if applicable). After a Nature save/delete -> resync.
     */
    public function enqueueProductsForBadge(Mibizum_Sync_Model_Nature $nature)
    {
        if (!$nature || !$nature->getId()) return 0;

        $resource = Mage::getSingleton('core/resource');
        $read = $resource->getConnection('core_read');

        $bridgeTable     = $resource->getTableName('mibizum_sync/natureCategory');
        $catEntityTable  = $resource->getTableName('catalog/category');
        $catProductTable = $resource->getTableName('catalog/category_product');

        $catIds = array();
        $rows = $read->fetchAll(
            "SELECT category_id, include_descendants FROM $bridgeTable WHERE badge_id = ?",
            array((int) $nature->getId())
        );
        foreach ($rows as $r) {
            $cid = (int) $r['category_id'];
            $catIds[] = $cid;
            if (!empty($r['include_descendants'])) {
                try {
                    $descendants = $read->fetchCol(
                        "SELECT entity_id FROM $catEntityTable
                         WHERE path LIKE :p1 OR path LIKE :p2",
                        array(':p1' => '%/' . $cid . '/%', ':p2' => '%/' . $cid)
                    );
                    foreach ($descendants as $d) $catIds[] = (int) $d;
                } catch (Exception $e) {
                    $this->log('enqueueProductsForBadge expand descendants failed: ' . $e->getMessage(), Zend_Log::WARN);
                }
            }
        }
        if (empty($catIds)) return 0;
        $catIds = array_values(array_unique($catIds));

        $placeholders = implode(',', array_fill(0, count($catIds), '?'));
        try {
            $productIds = $read->fetchCol(
                "SELECT DISTINCT product_id FROM $catProductTable WHERE category_id IN ($placeholders)",
                $catIds
            );
        } catch (Exception $e) {
            $this->log('enqueueProductsForBadge select products failed: ' . $e->getMessage(), Zend_Log::ERR);
            return 0;
        }
        if (empty($productIds)) return 0;

        try {
            $queue = Mage::getSingleton('mibizum_sync/indexer_queue');
            $count = (int) $queue->enqueueBulkUpsert(array_map('intval', $productIds), 'nature_badge_save');
            $this->log('enqueueProductsForBadge: ' . $count . ' products enqueued (badge id=' . $nature->getId() . ')', Zend_Log::INFO);
            return $count;
        } catch (Exception $e) {
            $this->log('enqueueProductsForBadge enqueue failed: ' . $e->getMessage(), Zend_Log::ERR);
            return 0;
        }
    }

    public function enqueueAllProductsForReindex($reason = 'config_changed')
    {
        try {
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
            if (empty($productIds)) return 0;
            $queue = Mage::getSingleton('mibizum_sync/indexer_queue');
            $count = (int) $queue->enqueueBulkUpsert(array_map('intval', $productIds), (string) $reason);
            $this->log('enqueueAllProductsForReindex: ' . $count . ' products enqueued (reason=' . $reason . ')', Zend_Log::INFO);
            return $count;
        } catch (Exception $e) {
            $this->log('enqueueAllProductsForReindex failed: ' . $e->getMessage(), Zend_Log::ERR);
            return 0;
        }
    }

    /**
     * Reports a sync run to the Mibizum backend.
     * Endpoint POST /api/v1/sync-runs - best-effort for now, we silence 404.
     */
    public function reportSyncRun(array $payload)
    {
        $apiUrl = $this->getApiUrl();
        $apiKey = $this->getApiKey();
        if (!$apiUrl || !$apiKey) return;

        if (!isset($payload['data_source_slug']) && $this->getDataSourceSlug()) {
            $payload['data_source_slug'] = $this->getDataSourceSlug();
        }

        try {
            $url = rtrim($apiUrl, '/') . '/api/v1/sync-runs';
            $ch = curl_init();
            curl_setopt_array($ch, array(
                CURLOPT_URL            => $url,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS     => 2000,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_HTTPHEADER     => array(
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                    'Accept: application/json',
                ),
            ));
            $resp = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code !== 201 && $code !== 200 && $code !== 404) {
                $this->log(
                    "reportSyncRun: backend responded HTTP $code",
                    Zend_Log::WARN,
                    array('response' => substr((string) $resp, 0, 500))
                );
            }
        } catch (Exception $e) {
            $this->log('reportSyncRun failed: ' . $e->getMessage(), Zend_Log::WARN);
        }
    }

    public function log($message, $level = Zend_Log::INFO, array $context = array())
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
        if (!empty($context)) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        Mage::log($line, $level, 'mibizum_sync.log', true);
    }

    /**
     * Reusable admin widget (Nature + Attribute Badge): a category picker with
     * an include_descendants flag.
     */
    public function getCategoryPickerMarkup(array $initial, $searchUrl, $name = 'categories_json')
    {
        $esc = function ($s) {
            return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        };

        $initJson = array();
        foreach ($initial as $r) {
            $initJson[] = array(
                'value'    => isset($r['value']) ? $r['value'] : (isset($r['category_id']) ? $r['category_id'] : null),
                'label'    => isset($r['label']) ? $r['label'] : '',
                'sublabel' => isset($r['sublabel']) ? $r['sublabel'] : (isset($r['path']) ? $r['path'] : ''),
                'extras'   => array(
                    'include_descendants' => !empty($r['include_descendants']),
                ),
            );
        }
        $extrasCfg = array(array(
            'key'     => 'include_descendants',
            'type'    => 'checkbox',
            'label'   => $this->__('Include subcategories'),
            'default' => true,
        ));

        $hCat   = $this->__('Category');
        $hSub   = $this->__('Include descendants');
        $hAct   = $this->__('Remove');
        $hPh    = $this->__('Type to search categories…');
        $hEmpty = $this->__('No categories assigned yet. Search above to add some.');
        $hNoRes = $this->__('No results.');

        return '<div class="mibizum-multiselect-table" '
             . 'data-mibizum-url="' . $esc($searchUrl) . '" '
             . 'data-mibizum-out-key="category_id" '
             . 'data-mibizum-name="' . $esc($name) . '" '
             . 'data-mibizum-empty="' . $esc($hNoRes) . '" '
             . 'data-mibizum-remove-label="' . $esc($hAct) . '" '
             . 'data-mibizum-extras="' . $esc(json_encode($extrasCfg)) . '" '
             . 'data-mibizum-initial="' . $esc(json_encode($initJson, JSON_UNESCAPED_UNICODE)) . '">'
             . '<input type="text" class="mst-input input-text" placeholder="' . $esc($hPh) . '" autocomplete="off" />'
             . '<ul class="mst-suggestions"></ul>'
             . '<table class="mst-table data" cellspacing="0">'
             . '<thead><tr>'
             . '<th>' . $esc($hCat) . '</th>'
             . '<th style="width:200px;">' . $esc($hSub) . '</th>'
             . '<th style="width:60px;text-align:center;">' . $esc($hAct) . '</th>'
             . '</tr></thead>'
             . '<tbody class="mst-rows"></tbody>'
             . '</table>'
             . '<p class="mst-empty">' . $esc($hEmpty) . '</p>'
             . '<input type="hidden" class="mst-value" name="' . $esc($name) . '" value="[]" />'
             . '</div>';
    }

    public function loadCategoryAssignmentsForBadge($tableEntity, $badgeId)
    {
        $out = array();
        $badgeId = (int) $badgeId;
        if ($badgeId <= 0) return $out;

        try {
            $resource = Mage::getSingleton('core/resource');
            $read     = $resource->getConnection('core_read');
            $table    = $resource->getTableName($tableEntity);
            $rows     = $read->fetchAll(
                $read->select()
                    ->from($table, array('category_id', 'include_descendants'))
                    ->where('badge_id = ?', $badgeId)
            );
            foreach ($rows as $r) {
                $cat = Mage::getModel('catalog/category')->load((int) $r['category_id']);
                if (!$cat || !$cat->getId()) continue;
                $ids = explode('/', (string) $cat->getPath());
                $ids = array_slice($ids, 2);
                $path = (string) $cat->getName();
                if (!empty($ids)) {
                    try {
                        $names = Mage::getResourceModel('catalog/category_collection')
                            ->addAttributeToSelect('name')
                            ->addFieldToFilter('entity_id', array('in' => $ids))
                            ->getColumnValues('name');
                        $path = implode(' > ', $names);
                    } catch (Exception $e) {}
                }
                $out[] = array(
                    'value'               => (int) $cat->getId(),
                    'label'               => (string) $cat->getName(),
                    'sublabel'            => $path,
                    'include_descendants' => (int) $r['include_descendants'] === 1,
                );
            }
        } catch (Exception $e) {
            Mage::logException($e);
        }
        return $out;
    }
}
