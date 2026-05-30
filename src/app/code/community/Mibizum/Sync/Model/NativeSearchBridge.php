<?php
/**
 * Mibizum_Sync_Model_NativeSearchBridge
 *
 * Override of `Mage_CatalogSearch_Model_Resource_Fulltext::prepareResult` so the
 * `/catalogsearch/result/?q=X` page (Magento's native search "Enter") uses the
 * Mibizum engine instead of MySQL MATCH...AGAINST. This way the customer sees
 * the SAME results from the native search box (Enter) and from the JS overlay
 * widget.
 *
 * Acknowledged trade-off: this couples to Magento. The SaaS vision is that the
 * full results page should be served by the engine itself (the search JS), just
 * like the overlay, so it is identical across platforms. Until the engine serves
 * the Enter via JS, this override is the best compromise:
 *   - The native Magento search box uses Mibizum.
 *   - Merchants with the widget disabled or JS blocked still have working search
 *     (through this server-side PHP path).
 *
 * Robustness:
 *   1. Calls the adapter with `facets=false`. The Enter only needs IDs to feed
 *      Magento's native collection. Requesting facets could fail if a schema
 *      filterableAttribute is not present in the search index (HTTP 400
 *      invalid_search_facets), and under Mage_CatalogSearch_Model_Resource_Fulltext
 *      that exception would break the whole Enter -> an unwanted MySQL fallback.
 *   2. Try/catch around the call. If the engine fails (5xx, network) we call
 *      `parent::prepareResult()` -> native MySQL. A degraded SERP beats a broken
 *      page.
 *   3. If the search side is not configured (connection off, or no search key /
 *      API URL) or the query is empty, we delegate to the parent without
 *      touching anything (the module never breaks the store). This gates on
 *      isSearchEnabled() (the SEARCH key), NOT the indexer key: the Enter works
 *      even when Magento is not the one indexing the catalog.
 *
 * Compatible with PHP 5.4 through 8.x.
 */
class Mibizum_Sync_Model_NativeSearchBridge extends Mage_CatalogSearch_Model_Resource_Fulltext
{
    /** Cap of IDs we request from the engine for the Enter. The backend caps at
     *  50 per call. For long SERPs (>50), Magento already paginates client-side
     *  from the collection; a later iteration could page with an offset when
     *  estimatedTotalHits > 50. For now, the first page with the 50 most
     *  relevant. */
    const ENTER_RESULTS_LIMIT = 50;

    /**
     * @param Mage_CatalogSearch_Model_Fulltext $object
     * @param string                            $queryText
     * @param Mage_CatalogSearch_Model_Query    $query
     * @return Mage_CatalogSearch_Model_Resource_Fulltext
     */
    public function prepareResult($object, $queryText, $query)
    {
        /** @var Mibizum_Sync_Helper_Data $helper */
        $helper = Mage::helper('mibizum_sync');

        // Skip straight away if the search side is not configured (connection
        // off, or no search key / API URL) or the query is empty. Gated by the
        // SEARCH key (isSearchEnabled), not the indexer key, so the Enter works
        // even on stores that do not index the catalog from Magento.
        if (!$helper->isSearchEnabled() || trim((string) $queryText) === '') {
            return parent::prepareResult($object, $queryText, $query);
        }

        try {
            /** @var Mibizum_Sync_Model_Search_Adapter $adapter */
            $adapter = Mage::getModel('mibizum_sync/search_adapter');
            $result  = $adapter->search(array(
                'q'        => $queryText,
                'limit'    => self::ENTER_RESULTS_LIMIT,
                'offset'   => 0,
                'strategy' => 'auto',   // try 'all' and fall back to 'last' on 0 hits
            ));

            // Pull the engine IDs and map them to catalog entity_ids. In Mibizum
            // the document `id` is the SKU (string); the numeric entity_id lives
            // in `product_id`. The catalogsearch_result table expects entity_ids,
            // so we use product_id.
            $entityIds = array();
            if (isset($result['hits']) && is_array($result['hits'])) {
                foreach ($result['hits'] as $hit) {
                    if (isset($hit['product_id']) && $hit['product_id']) {
                        $entityIds[] = (int) $hit['product_id'];
                    } elseif (isset($hit['id']) && is_numeric($hit['id'])) {
                        // Fallback: if `id` is numeric, assume it is the entity_id.
                        $entityIds[] = (int) $hit['id'];
                    }
                }
            }

            // If the engine returned 0 valid hits we do NOT fall back to native
            // MySQL: a real 0 hits means "not found in the engine" and we want the
            // SERP to reflect that, not have MySQL return noise. We only fall back
            // to the parent on EXCEPTIONS (5xx/network), caught below.
            $this->_writeResultIdsToTable($query, $entityIds);

            // CRITICAL: Mage_CatalogSearch_Model_Resource_Fulltext_Collection
            // calls `prepareResult()->getResource()->getFoundData()` to feed the
            // SERP. `getFoundData()` returns the protected `_foundData` property
            // (array product_id => relevance) that the parent fills via fetchPairs
            // over catalogsearch_fulltext.
            //
            // If we do NOT set _foundData, getFoundIds() returns [] and the
            // collection is empty ("Your search returns no results") even when
            // catalogsearch_result has the right rows.
            //
            // We build the map respecting the engine's hit ORDER (most relevant
            // first) using a descending relevance: hit #0 = count, hit #1 =
            // count-1, etc. Magento uses this number for ORDER BY relevance DESC
            // in the SERP.
            $foundData = array();
            $relevance = count($entityIds);
            foreach ($entityIds as $pid) {
                if (!isset($foundData[$pid])) {
                    $foundData[$pid] = $relevance;
                    $relevance--;
                }
            }
            $this->_foundData = $foundData;
            return $this;

        } catch (Exception $e) {
            // If the engine fails (5xx, network, invalid search_api_key) we log
            // and fall back to native MySQL so the store does not break.
            $helper->log(
                'NativeSearchBridge::prepareResult engine failed - fallback to MySQL native: ' . $e->getMessage(),
                Zend_Log::WARN,
                array('query' => $queryText)
            );
            return parent::prepareResult($object, $queryText, $query);
        }
    }

    /**
     * Write the resulting entity_ids into `catalogsearch_result` so Magento's
     * native collection picks them up through its usual INNER JOIN. Mirrors the
     * pattern parent::prepareResult uses, but with OUR IDs instead of the ones
     * from MATCH...AGAINST.
     *
     * @param Mage_CatalogSearch_Model_Query $query
     * @param array $entityIds
     */
    protected function _writeResultIdsToTable($query, array $entityIds)
    {
        $adapter = $this->_getWriteAdapter();
        $table   = $this->getTable('catalogsearch/result');

        // Clear previous results for this query_id.
        $adapter->delete($table, array('query_id = ?' => (int) $query->getId()));

        if (empty($entityIds)) {
            return;
        }

        // Insert new rows. relevance = inverse position (the engine already
        // orders by relevance, so we keep the engine's order).
        $rows = array();
        $relevance = count($entityIds);
        foreach ($entityIds as $eid) {
            $rows[] = array(
                'query_id'   => (int) $query->getId(),
                'product_id' => $eid,
                'relevance'  => $relevance--,
            );
        }

        $adapter->insertMultiple($table, $rows);
    }
}
