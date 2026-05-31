<?php
/**
 * Mibizum_Sync_Model_Search_Adapter
 *
 * Server-side HTTP client against `https://app.mibizum.io/api/v1/search`.
 * Used by the native search override (`Model/NativeSearchBridge.php`) so the
 * `/catalogsearch/result/?q=...` page uses the Mibizum engine (consistent with
 * the JS widget) instead of Magento's native MySQL MATCH...AGAINST.
 *
 * Design:
 *   - Bearer auth with the SEARCH key (NOT the indexer one): scope=search.
 *     Configured in admin via mibizum_sync/connection/search_api_key (next to
 *     the existing indexer api_key).
 *   - Engine params (rankingScoreThreshold, matchingStrategy) are read from
 *     Helper::getSearchEngineParams(), which in turn queries the SaaS
 *     runtime-config with a 60s cache.
 *   - Robustness: the caller (native search override) can request `facets=false`
 *     for the Enter path so it does not break if a schema filterableAttribute is
 *     missing from the index (those attributes are nice-to-have for the overlay
 *     sidebar, but the Enter only needs the hit IDs).
 *
 * Compatible with PHP 5.4 through 8.x.
 */
class Mibizum_Sync_Model_Search_Adapter
{
    /** Backend cap per call. If we need more (rare, very long SERPs), iterate
     *  with an offset. */
    const MAX_LIMIT = 50;

    /**
     * Search.
     *
     * @param array $params {
     *     q:        string (required)
     *     limit:    int (default + max = MAX_LIMIT = 50)
     *     offset:   int (default 0)
     *     source:   string (data source slug; default from Helper)
     *     facets:   bool (default false; the Enter does not need them)
     *     strategy: string ('all' | 'last' | 'auto'; default 'auto')
     * }
     * @return array {
     *     hits:        array,
     *     query:       string,
     *     totalHits:   int,
     *     processingMs: int,
     *     degraded:    bool,
     *     strategyUsed: string,
     *     raw:         array (full backend response, in case the caller needs more)
     * }
     * @throws Exception on HTTP 5xx, network failure, or invalid key.
     */
    public function search(array $params)
    {
        $q = isset($params['q']) ? (string) $params['q'] : '';
        if ($q === '') {
            return array(
                'hits'         => array(),
                'query'        => '',
                'totalHits'    => 0,
                'processingMs' => 0,
                'degraded'     => false,
                'strategyUsed' => 'all',
                'raw'          => null,
            );
        }

        /** @var Mibizum_Sync_Helper_Data $helper */
        $helper = Mage::helper('mibizum_sync');

        // Always clamp to MAX_LIMIT (50). The default (no param) must also respect
        // it: the backend rejects limit > 50 with HTTP 400 invalid_params.
        $limit  = min(isset($params['limit']) ? (int) $params['limit'] : self::MAX_LIMIT, self::MAX_LIMIT);
        if ($limit < 1) { $limit = self::MAX_LIMIT; }
        $offset = isset($params['offset']) ? max((int) $params['offset'], 0)              : 0;
        $source = isset($params['source']) ? (string) $params['source']                   : $helper->getDataSourceSlug();
        $strategy = isset($params['strategy']) ? (string) $params['strategy']             : 'auto';

        $apiUrl = $helper->getApiUrl();
        $apiKey = $helper->getSearchApiKey();
        if (!$apiUrl || !$apiKey) {
            throw new Exception('Search adapter: api_url or search_api_key not configured in mibizum_sync/connection');
        }

        $query = array(
            'q'        => $q,
            'limit'    => $limit,
            'offset'   => $offset,
            'strategy' => $strategy,
        );
        if ($source) {
            $query['source'] = $source;
        }
        $url = rtrim($apiUrl, '/') . '/api/v1/search?' . http_build_query($query);

        // The backend validates Origin against the search key's allowed origins.
        // Server-side we are calling FROM the merchant host (where Magento runs);
        // Mage::getBaseUrl() knows that host.
        $host = parse_url(Mage::getBaseUrl(), PHP_URL_HOST);
        $origin = $host ? ('https://' . $host) : null;

        list($code, $body) = $this->_request('GET', $url, $apiKey, $helper, $origin);

        if ($code === 200) {
            $json = json_decode((string) $body, true);
            if (!is_array($json)) {
                throw new Exception('Search adapter: response is not JSON (HTTP 200 but invalid body)');
            }
            return array(
                'hits'         => isset($json['hits'])         ? $json['hits']                : array(),
                'query'        => isset($json['query'])        ? (string) $json['query']      : $q,
                'totalHits'    => isset($json['totalHits'])    ? (int)    $json['totalHits']  : 0,
                'processingMs' => isset($json['processingMs']) ? (int)    $json['processingMs']: 0,
                'degraded'     => isset($json['degraded'])     ? (bool)   $json['degraded']   : false,
                'strategyUsed' => isset($json['strategyUsed']) ? (string) $json['strategyUsed']: $strategy,
                'raw'          => $json,
            );
        }

        // 4xx errors -> do not retry, propagate. 5xx -> the caller decides
        // (NativeSearchBridge::prepareResult should fall back to parent::prepareResult).
        throw new Exception(sprintf(
            'Search adapter: backend HTTP %d - %s',
            $code, substr((string) $body, 0, 300)
        ));
    }

    /**
     * curl wrapper. Short timeouts so the Enter page render is not blocked (the
     * customer is waiting for Magento's SERP).
     * @return array [int httpCode, string body]
     */
    protected function _request($method, $url, $bearer, $helper, $origin = null)
    {
        $headers = array(
            'Authorization: Bearer ' . $bearer,
            'Accept: application/json',
        );
        if ($origin) {
            $headers[] = 'Origin: ' . $origin;
        }
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $helper->getTimeoutSeconds(),
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER     => $headers,
        ));
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new Exception('Search adapter: curl error - ' . $err);
        }
        return array($code, $body);
    }
}
