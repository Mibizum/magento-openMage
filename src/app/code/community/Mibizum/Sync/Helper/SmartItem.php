<?php
/**
 * Mibizum_Sync_Helper_SmartItem
 *
 * Thin Smart Item (ingredient) helper: the ficha feature, folded into
 * Mibizum_Sync (no separate Mibizum_Ingredient module). The Mibizum panel is
 * the source of truth; this module only RENDERS the public ficha, reading from
 *   GET /api/v1/smart-items/{slug}?source={slug}
 * on the Mibizum SaaS. Reuses the module's existing Connection config (API URL,
 * public/search key, data source slug) via the main Mibizum_Sync helper.
 *
 * Compatible with PHP 5.4+.
 */
class Mibizum_Sync_Helper_SmartItem extends Mage_Core_Helper_Abstract
{
    const XML_PATH_ENABLED    = 'mibizum_sync/frontend/smartitems_enabled';
    const XML_PATH_URL_PREFIX = 'mibizum_sync/frontend/smartitems_url_prefix';
    const CACHE_TTL_SECONDS   = 300;

    /** Master toggle for the public ficha (and its router). */
    public function isFichaEnabled()
    {
        return (bool) Mage::getStoreConfigFlag(self::XML_PATH_ENABLED);
    }

    /** URL prefix for the ficha route (default "ingredientes"). */
    public function getUrlPrefix()
    {
        $p = trim((string) Mage::getStoreConfig(self::XML_PATH_URL_PREFIX), "/ \t\n");
        return $p !== '' ? $p : 'ingredientes';
    }

    /** Absolute public URL of a Smart Item ficha. */
    public function getFichaUrl($slug)
    {
        return rtrim(Mage::getBaseUrl(), '/') . '/' . $this->getUrlPrefix() . '/' . rawurlencode((string) $slug);
    }

    /**
     * Fetch one enabled Smart Item from the Mibizum SaaS, cached briefly. Returns
     * the `smartItem` blob (already enriched server-side: statusLabel, statusBg,
     * statusFg, substatusLabel, ...) or null if it does not exist / not reachable.
     *
     * @param  string $slug
     * @return array|null
     */
    public function fetchSmartItem($slug)
    {
        $slug = trim((string) $slug);
        if ($slug === '') {
            return null;
        }

        $cache    = Mage::app()->getCache();
        $cacheKey = 'mibizum_sync_si_' . md5($slug);
        $cached   = $cache->load($cacheKey);
        if ($cached !== false) {
            $decoded = @json_decode($cached, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        /** @var Mibizum_Sync_Helper_Data $sync */
        $sync   = Mage::helper('mibizum_sync');
        $apiUrl = rtrim((string) $sync->getApiUrl(), '/');
        $key    = (string) $sync->getSearchApiKey();   // public/search key
        $source = (string) $sync->getDataSourceSlug();
        if ($apiUrl === '' || $key === '' || $source === '') {
            return null;
        }

        $host   = parse_url(Mage::getBaseUrl(), PHP_URL_HOST);
        $origin = $host ? ('https://' . $host) : null;
        $url    = $apiUrl . '/api/v1/smart-items/' . rawurlencode($slug) . '?source=' . urlencode($source);

        $headers = array(
            'Authorization: Bearer ' . $key,
            'Accept: application/json',
        );
        if ($origin) {
            $headers[] = 'Origin: ' . $origin;
        }

        $body = false;
        $code = 0;
        try {
            $ch = curl_init();
            curl_setopt_array($ch, array(
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT        => 4,
                CURLOPT_HTTPHEADER     => $headers,
            ));
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } catch (Exception $e) {
            return null;
        }

        if ($code !== 200 || !$body) {
            return null;
        }
        $json = @json_decode($body, true);
        if (!is_array($json) || empty($json['smartItem'])) {
            return null;
        }

        $cache->save(
            json_encode($json['smartItem']),
            $cacheKey,
            array('mibizum_sync_smartitems'),
            self::CACHE_TTL_SECONDS
        );
        return $json['smartItem'];
    }

    /** Title/heading of the listing page. Configurable per merchant/vertical
     *  (e.g. "Ingredientes", "Próximamente", "I+D", "Raw materials"). */
    public function getListTitle()
    {
        $t = trim((string) Mage::getStoreConfig('mibizum_sync/frontend/smartitems_list_title'));
        return $t !== '' ? $t : 'Ingredientes';
    }

    /**
     * Fetch the LIST of enabled Smart Items from the SaaS (cached briefly), for
     * the public listing page `/{url_prefix}/`. Returns the enriched items
     * (statusLabel/statusBg/statusFg/substatusLabel, ...) or [] on any failure.
     *
     * @param  int $limit
     * @return array
     */
    public function fetchSmartItems($limit = 200)
    {
        $limit = max(1, min(200, (int) $limit));

        $cache    = Mage::app()->getCache();
        $cacheKey = 'mibizum_sync_si_list_' . $limit;
        $cached   = $cache->load($cacheKey);
        if ($cached !== false) {
            $decoded = @json_decode($cached, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        /** @var Mibizum_Sync_Helper_Data $sync */
        $sync   = Mage::helper('mibizum_sync');
        $apiUrl = rtrim((string) $sync->getApiUrl(), '/');
        $key    = (string) $sync->getSearchApiKey();
        $source = (string) $sync->getDataSourceSlug();
        if ($apiUrl === '' || $key === '' || $source === '') {
            return array();
        }

        $host   = parse_url(Mage::getBaseUrl(), PHP_URL_HOST);
        $origin = $host ? ('https://' . $host) : null;
        $url    = $apiUrl . '/api/v1/smart-items?source=' . urlencode($source) . '&limit=' . $limit;

        $headers = array('Authorization: Bearer ' . $key, 'Accept: application/json');
        if ($origin) {
            $headers[] = 'Origin: ' . $origin;
        }

        $body = false;
        $code = 0;
        try {
            $ch = curl_init();
            curl_setopt_array($ch, array(
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_HTTPHEADER     => $headers,
            ));
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } catch (Exception $e) {
            return array();
        }

        if ($code !== 200 || !$body) {
            return array();
        }
        $json = @json_decode($body, true);
        if (!is_array($json)) {
            return array();
        }
        $items = isset($json['items']) && is_array($json['items'])
            ? $json['items']
            : (isset($json['smartItems']) && is_array($json['smartItems']) ? $json['smartItems'] : array());

        $cache->save(json_encode($items), $cacheKey, array('mibizum_sync_smartitems'), self::CACHE_TTL_SECONDS);
        return $items;
    }
}
