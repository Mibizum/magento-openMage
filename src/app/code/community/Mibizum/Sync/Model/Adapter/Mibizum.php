<?php
/**
 * Mibizum_Sync_Model_Adapter_Mibizum
 *
 * HTTP client against the Mibizum SaaS backend. It replaces an earlier
 * search adapter that talked directly to the engine with scoped engine keys; in
 * this module there is NO direct engine access - everything goes through
 * `/api/v1/index` with a Bearer API key.
 *
 * Keeps the SAME public signature as the original search adapter so Worker and
 * the scheduler need no structural changes:
 *
 *   indexDocuments($indexNameIgnored, array $docs)        - POST /api/v1/index
 *   deleteDocumentsByIds($indexNameIgnored, array $ids)   - DELETE individual
 *   createIndex($name, $primaryKey)                       - no-op (backend creates it)
 *   updateSettings($name, array $settings)                - no-op (backend applies it)
 *
 * The $indexName parameter is ignored - the Mibizum backend resolves the search
 * index from the tenant's API key + dataSource slug. We keep it in the signature
 * for compatibility with the legacy code.
 *
 * Compatible with PHP 5.4 through 8.x.
 */
class Mibizum_Sync_Model_Adapter_Mibizum
{
    /** Backend cap per POST. If docs exceed it, we split into chunks. */
    const MAX_BATCH_SIZE = 500;

    /**
     * Publish N documents to the tenant's index. Idempotent - upsert by the
     * backend primary key (`id`). If the array exceeds MAX_BATCH_SIZE, the
     * caller must split it (Worker already does, via $_pushChunk).
     *
     * @param string $indexNameIgnored  Signature compat; ignored.
     * @param array  $documents         Array of JSON-serializable documents, each with an `id`.
     * @return array {accepted, taskUid}
     * @throws Exception on HTTP != 2xx, network failure, or invalid payload.
     */
    public function indexDocuments($indexNameIgnored, array $documents)
    {
        if (empty($documents)) {
            return array('accepted' => 0, 'taskUid' => null);
        }
        if (count($documents) > self::MAX_BATCH_SIZE) {
            throw new Exception(sprintf(
                'indexDocuments: batch too large (%d > %d). The caller must split it.',
                count($documents), self::MAX_BATCH_SIZE
            ));
        }

        $helper  = Mage::helper('mibizum_sync');
        $url     = $helper->getApiUrl() . '/api/v1/index';
        $payload = array('documents' => $documents);
        $slug    = $helper->getDataSourceSlug();
        if ($slug) {
            $payload['source'] = $slug;
        }

        list($code, $body) = $this->_request('POST', $url, json_encode($payload));

        if ($code === 202 || $code === 200) {
            $json = json_decode((string) $body, true);
            return array(
                'accepted' => isset($json['accepted']) ? (int) $json['accepted'] : count($documents),
                'taskUid'  => isset($json['taskUid'])  ? $json['taskUid']        : null,
            );
        }

        throw new Exception(sprintf(
            'indexDocuments: backend responded HTTP %d - %s',
            $code, substr((string) $body, 0, 300)
        ));
    }

    /**
     * Delete N documents by id. The backend has no batch DELETE in v1, so we
     * iterate. Idempotent - DELETE of a missing id does not fail.
     *
     * For Magento 1.x the `ids` are SKUs (Mibizum id = SKU; see ProductMapper).
     * If an id is a numeric entity_id (legacy/compat), the backend accepts it as
     * a string anyway.
     *
     * @param string $indexNameIgnored  Signature compat; ignored.
     * @param array  $ids               Array of ids (SKUs).
     * @return int Number of successful DELETEs.
     * @throws Exception if ALL fail. If only some fail, logs a warning and continues.
     */
    public function deleteDocumentsByIds($indexNameIgnored, array $ids)
    {
        if (empty($ids)) return 0;

        $helper = Mage::helper('mibizum_sync');
        $slug   = $helper->getDataSourceSlug();

        $okCount   = 0;
        $errors    = array();
        foreach ($ids as $id) {
            $idStr = (string) $id;
            if ($idStr === '') continue;

            $url = $helper->getApiUrl() . '/api/v1/index/' . rawurlencode($idStr);
            if ($slug) {
                $url .= '?source=' . rawurlencode($slug);
            }

            try {
                list($code, $body) = $this->_request('DELETE', $url, null);
                if ($code === 200 || $code === 202) {
                    $okCount++;
                } else {
                    $errors[] = "id=$idStr: HTTP $code - " . substr((string) $body, 0, 100);
                }
            } catch (Exception $e) {
                $errors[] = "id=$idStr: " . $e->getMessage();
            }
        }

        if ($okCount === 0 && !empty($errors)) {
            // All failed - total fallback; propagate so the queue marks them as
            // failed and retries with backoff.
            throw new Exception('deleteDocumentsByIds: ALL failed - ' . implode(' | ', array_slice($errors, 0, 5)));
        }
        if (!empty($errors)) {
            $helper->log(
                sprintf('deleteDocumentsByIds: %d OK, %d failed', $okCount, count($errors)),
                Zend_Log::WARN,
                array('errors' => array_slice($errors, 0, 10))
            );
        }
        return $okCount;
    }

    /**
     * No-op - the Mibizum backend creates the search index automatically on the
     * first POST /api/v1/index (lazy ensure). Kept for compatibility with an
     * earlier flow that called this method; the scheduler no longer does.
     */
    public function createIndex($name, $primaryKey = 'id')
    {
        // No-op
        return true;
    }

    /**
     * No-op - the backend applies the index settings (searchable, filterable,
     * sortable, rankingRules, typoTolerance, stopWords) based on its internal
     * schema + per-tenant override. The module has no say here.
     *
     * If a future version lets the merchant publish custom searchable/filterable
     * attributes, the module would POST to a new backend endpoint such as
     * `POST /api/v1/schema/declare-attributes`. Not applicable for now.
     */
    public function updateSettings($name, array $settings)
    {
        // No-op
        return true;
    }

    /**
     * Approximate "documents in the index" for the Reindex panel + install
     * wizard. The SaaS has no dedicated engine-stats proxy yet, so we use a
     * match-all search (`q=*`) and report its totalHits. It is an indicator,
     * not an exact engine count, but it is live (grows as indexing progresses)
     * and far better than the previous null stub (which left the counter at 0).
     *
     * Returns null on any failure so the caller shows "-" instead of breaking.
     *
     * Future: a dedicated GET /api/v1/stats?source=X would give the exact count.
     *
     * @param string $indexName Ignored (the backend resolves the index from the key).
     * @return array|null  e.g. array('numberOfDocuments' => 1035)
     */
    public function getStats($indexName = null)
    {
        try {
            $res = Mage::getModel('mibizum_sync/search_adapter')
                ->search(array('q' => '*', 'limit' => 1, 'facets' => false));
            if (is_array($res) && isset($res['totalHits'])) {
                return array('numberOfDocuments' => (int) $res['totalHits']);
            }
        } catch (Exception $e) {
            // Fall through to null; the panel/wizard will show "-".
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // Bulk upload (Cloudflare-safe full reindex)
    // -------------------------------------------------------------------------

    /**
     * Upload a gzip-compressed JSONL file for bulk indexing. The backend
     * processes it asynchronously and returns a taskId for polling.
     *
     * This replaces the HTTP-per-chunk pattern that triggers Cloudflare rate
     * limits during full reindex.
     *
     * @param string      $filePath Absolute path to the .jsonl.gz file.
     * @param string|null $source   Data source slug (multi-store).
     * @return array {taskId, totalLines, status}
     * @throws Exception on HTTP != 202, network failure, or missing file.
     */
    public function uploadBulkFile($filePath, $source = null)
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new Exception('uploadBulkFile: file not found or not readable: ' . $filePath);
        }

        $helper  = Mage::helper('mibizum_sync');
        $url     = $helper->getApiUrl() . '/api/v1/ingest/upload';
        $apiKey  = $helper->getApiKey();
        $timeout = max(120, $helper->getTimeoutSeconds() * 4);

        if ($apiKey === '') {
            throw new Exception('Mibizum_Sync: API key not configured');
        }

        $postFields = array();
        if ($source) {
            $postFields['source'] = $source;
        }
        if (class_exists('CURLFile')) {
            $postFields['file'] = new CURLFile($filePath, 'application/gzip', basename($filePath));
        } else {
            $postFields['file'] = '@' . $filePath . ';type=application/gzip;filename=' . basename($filePath);
        }

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => array(
                'Authorization: Bearer ' . $apiKey,
                'Accept: application/json',
                'User-Agent: Mibizum-Sync-Adapter/0.2.0 (Magento 1.x)',
            ),
        ));

        $resp     = curl_exec($ch);
        $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $netError = curl_error($ch);
        curl_close($ch);

        if ($code === 0) {
            throw new Exception('uploadBulkFile: network error - ' . ($netError ?: 'unknown'));
        }
        if ($code !== 202) {
            $detail = substr((string) $resp, 0, 300);
            $json   = json_decode((string) $resp, true);
            if (is_array($json) && isset($json['error'])) {
                $detail = $json['error'];
                if (isset($json['message'])) {
                    $detail .= ': ' . $json['message'];
                }
            }
            throw new Exception(sprintf('uploadBulkFile: backend responded HTTP %d - %s', $code, $detail));
        }

        $json = json_decode((string) $resp, true);
        return array(
            'taskId'     => isset($json['taskId'])     ? $json['taskId']          : null,
            'totalLines' => isset($json['totalLines']) ? (int) $json['totalLines'] : 0,
            'status'     => isset($json['status'])     ? $json['status']           : 'unknown',
        );
    }

    /**
     * Poll the status of a bulk upload task.
     *
     * @param string $taskId
     * @return array {status, processed, total, errors, completedAt, ...}
     * @throws Exception on network failure.
     */
    public function pollBulkUpload($taskId)
    {
        $helper = Mage::helper('mibizum_sync');
        $url    = $helper->getApiUrl() . '/api/v1/ingest/upload/' . rawurlencode($taskId);

        list($code, $body) = $this->_request('GET', $url, null);

        if ($code === 200) {
            $json = json_decode((string) $body, true);
            return is_array($json) ? $json : array('status' => 'error', 'message' => 'Invalid response');
        }
        if ($code === 404) {
            return array('status' => 'not_found');
        }
        throw new Exception(sprintf('pollBulkUpload: HTTP %d - %s', $code, substr((string) $body, 0, 300)));
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Make an HTTP request. Returns [code, body]. Throws an Exception on a
     * NETWORK error (not on a status code; the caller decides what to do with
     * each code). Uses native cURL for fine-grained timeout control.
     */
    protected function _request($method, $url, $body)
    {
        $helper  = Mage::helper('mibizum_sync');
        $apiKey  = $helper->getApiKey();
        $timeout = $helper->getTimeoutSeconds();

        if ($apiKey === '') {
            throw new Exception('Mibizum_Sync: API key not configured');
        }

        $ch = curl_init();
        $headers = array(
            'Authorization: Bearer ' . $apiKey,
            'Accept: application/json',
            'User-Agent: Mibizum-Sync-Adapter/0.2.0 (Magento 1.x)',
        );
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        curl_setopt_array($ch, array(
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => max(2, intval($timeout / 3)),
            CURLOPT_HTTPHEADER     => $headers,
        ));
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $resp     = curl_exec($ch);
        $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $netError = curl_error($ch);
        curl_close($ch);

        if ($code === 0) {
            throw new Exception('Network error: ' . ($netError ?: 'unknown'));
        }
        return array($code, (string) $resp);
    }
}
