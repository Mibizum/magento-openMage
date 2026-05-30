<?php
/**
 * Mibizum_Sync_Model_Config_Schema
 *
 * Defines the SCHEMA of the index document for the products index.
 *
 * Key concept: there are two kinds of fields in the document:
 *
 *   1. SYNTHETIC FIELDS (document contract)
 *      Not Magento attributes. They are computed/derived in ProductMapper from
 *      the product entity (displayed price, evaluated in_stock, categories as
 *      readable names, etc.). They are part of the document contract: whoever
 *      consumes the index relies on them existing. Declared here in code.
 *      Adding/removing one requires touching both ProductMapper and this class.
 *      They are NOT configurable from admin.
 *
 *   2. MAGENTO ATTRIBUTES (extensible)
 *      Custom catalog attributes (CAS, EINECS, INCI, botanical_name, ...) that
 *      the merchant enables/configures from Mibizum > Search > Indexable
 *      attributes. Persisted in mibizum_sync_attribute_config with the flags
 *      is_searchable / is_filterable / is_sortable / enabled.
 *
 * This class centralizes the merge of both kinds into a single source of truth
 * consumed by:
 *   - Scheduler::applyEngineSettings (applies the config to the index on each reindex).
 *   - Search/Adapter (validates filters in queries).
 *
 * Compatible with PHP 5.4 through 8.x.
 */
class Mibizum_Sync_Model_Config_Schema
{
    /**
     * Highest-weight synthetic searchable fields. The order of the engine's
     * `searchableAttributes` list IS the priority (the `attribute` ranking
     * rule): earlier means heavier. Nothing goes above these; below come the
     * Magento attributes (botanical family, INCI, CAS...) and last the SKU
     * (getSyntheticSearchableFieldsLow).
     *
     * NOTE: `short_description` and `description` were REMOVED from the
     * searchable fields. Indexing them created noise (dozens of records mention
     * a word without being titled that way). The document STILL carries them
     * (ProductMapper writes them, they stay stored); the engine simply no longer
     * searches in them. To re-enable description search, add them back here and
     * re-apply settings.
     */
    public static function getSyntheticSearchableFields()
    {
        return array('name');
    }

    /**
     * Lowest-weight synthetic searchable fields: appended at the END of the
     * `searchableAttributes` list, after the Magento attributes.
     *
     *  - `sku`: searchable (never ignored) but below the name.
     *  - `categories`: the product's category names. Lets a term match products
     *    in a category even when the word is not in the product name. It goes
     *    LAST (minimum weight): a name match always wins. Whether the engine
     *    actually SEARCHES in categories is decided per-query via
     *    `attributesToSearchOn` (the panel's `search_in_categories` setting) -
     *    the field is always in the index, included or excluded per query.
     */
    public static function getSyntheticSearchableFieldsLow()
    {
        return array('sku', 'categories');
    }

    /**
     * Searchable fields WITHOUT `categories`. This is the `attributesToSearchOn`
     * sent when the `search_in_categories` setting is off: restricts the search
     * to everything searchable except categories.
     *
     * @return string[]
     */
    public static function getSearchableWithoutCategories()
    {
        $schema = self::buildSearchSchema();
        $out = array();
        foreach ($schema['searchable'] as $field) {
            if ($field !== 'categories') {
                $out[] = $field;
            }
        }
        return $out;
    }

    /**
     * Synthetic filterable fields. Magento attributes from the table with
     * is_filterable=1 are added on top.
     *
     * `id` is the document primary key and must be filterable for the panel's
     * POST /api/products/by-ids endpoint (re-hydrating products saved in
     * curation rules). Without a filterable `id`, by-ids returns 503 and the
     * rule editor shows "Product #XXXX" without an image when editing saved
     * rules.
     */
    public static function getSyntheticFilterableFields()
    {
        return array('id', 'in_stock', 'in_offer', 'categories', 'doc_type', 'is_visible');
    }

    /**
     * Synthetic sortable fields. Magento attributes from the table with
     * is_sortable=1 are added on top.
     */
    public static function getSyntheticSortableFields()
    {
        return array('price', 'name', 'created_at');
    }

    /**
     * Builds the full engine settings by merging the synthetic fields
     * (contract) + enabled attributes from mibizum_sync_attribute_config.
     * Single source of truth.
     *
     * @return array {searchable: string[], filterable: string[], sortable: string[]}
     */
    public static function buildSearchSchema()
    {
        $searchable = self::getSyntheticSearchableFields();
        $filterable = self::getSyntheticFilterableFields();
        $sortable   = self::getSyntheticSortableFields();

        $db = Mage::getSingleton('core/resource')->getConnection('core_read');
        $table = Mage::getSingleton('core/resource')->getTableName('mibizum_sync/attributeConfig');
        $rows = $db->fetchAll("SELECT * FROM $table WHERE enabled = 1 ORDER BY display_order");

        foreach ($rows as $r) {
            if (!empty($r['is_searchable'])) {
                $searchable[] = $r['attribute_code'];
            }
            if (!empty($r['is_filterable'])) {
                $filterable[] = $r['attribute_code'];
            }
            if (!empty($r['is_sortable'])) {
                $sortable[] = $r['attribute_code'];
            }
        }

        // SKU at the end of searchable: searchable but lowest weight, below even
        // the Magento attributes (see getSyntheticSearchableFieldsLow).
        foreach (self::getSyntheticSearchableFieldsLow() as $lowField) {
            $searchable[] = $lowField;
        }

        return array(
            'searchable' => array_values(array_unique($searchable)),
            'filterable' => array_values(array_unique($filterable)),
            'sortable'   => array_values(array_unique($sortable)),
        );
    }

    /**
     * Direct helper for the filterable list (frequently used in Search/Adapter
     * when validating incoming filters from the frontend).
     *
     * @return string[]
     */
    public static function getFilterableFields()
    {
        $schema = self::buildSearchSchema();
        return $schema['filterable'];
    }

    /**
     * Engine ranking rules. Order matters: each rule refines the previous one.
     * The current values are the ones that worked best after testing with real
     * queries.
     *
     * NOT exposed as an admin setting: changing them without understanding the
     * engine breaks result quality (e.g. moving "exactness" before "typo" makes
     * exact typos win over acceptable ones).
     *
     * If a tweak is needed, edit here and re-apply settings from
     * Mibizum > Search > Reindex > "Re-apply settings".
     *
     * @return string[]
     */
    public static function getRankingRules()
    {
        return array('words', 'typo', 'proximity', 'attribute', 'sort', 'exactness');
    }

    /**
     * Engine typo tolerance config. Allows 1 typo if the word has >=4
     * characters, 2 typos if >=8. Balances precision/recall for long botanical
     * vocabulary (cymbopogon, citronella, lavandula).
     *
     * NOT admin-configurable for the same reason as getRankingRules.
     *
     * @return array
     */
    public static function getTypoToleranceConfig()
    {
        return array(
            'enabled' => true,
            'minWordSizeForTypos' => array(
                'oneTypo'  => 4,
                'twoTypos' => 8,
            ),
        );
    }

    /**
     * Cap of results the engine returns/paginates for a search
     * (`pagination.maxTotalHits`). The engine defaults to 1000; a large catalog
     * already exceeds that. Without a cap >= catalog size, the overlay cannot
     * count the real total of results (its counter would max out at 1000) nor
     * paginate to the end.
     *
     * Generous margin over the current catalog so it does not need touching on
     * every growth. It is a technical limit, not admin-configurable.
     *
     * @return int
     */
    public static function getMaxTotalHits()
    {
        return 5000;
    }

    /**
     * Stop words applied to the search INDEX (`stopWords` setting).
     *
     * Empty on purpose. The engine must NOT ignore any word: it has to search
     * the literal text. If the engine drops "of", then "essential oil of" and
     * "oil of" become indistinguishable and "oil of X" cannot be separated from
     * "essential oil of X".
     *
     * The stop-word list does not disappear: it still exists but only to trim
     * the LAST typed term - see getTrailingTrimWords(). That is the only
     * function it keeps.
     *
     * @return string[]  Always empty.
     */
    public static function getIndexStopWords()
    {
        return array();
    }

    /**
     * ES/PT/EN stop words used ONLY to trim the last term the user typed
     * ("oil of " -> "oil"). They are NOT sent to the engine as `stopWords`
     * (that is getIndexStopWords, empty).
     *
     * The engine treats the last term as an autocomplete prefix; if it is a
     * worthless stop word ("of") it is best removed before firing the query.
     * Trimming only affects words of >= 2 characters (see
     * Adapter::_trimTrailingStopWords / JS trimTrailingStopWords).
     *
     * Conservative list: only short articles/prepositions/conjunctions with no
     * discriminating value. Avoid words that could be part of a technical name
     * (e.g. "use", "life", etc).
     *
     * @return string[]
     */
    public static function getTrailingTrimWords()
    {
        return array(
            // Spanish articles
            'el', 'la', 'los', 'las', 'un', 'una', 'unos', 'unas',
            // Spanish prepositions
            'a', 'al', 'ante', 'bajo', 'con', 'contra', 'de', 'del',
            'desde', 'durante', 'en', 'entre', 'hacia', 'hasta', 'mediante',
            'para', 'por', 'segun', 'sin', 'sobre', 'tras',
            // Spanish conjunctions
            'y', 'e', 'ni', 'o', 'u', 'pero', 'sino', 'que', 'aunque',
            // Common pronouns that rarely appear in product names
            'mi', 'tu', 'su', 'sus', 'mis', 'tus', 'lo', 'le', 'les',
            // EN articles/prepositions (catalogs sometimes have loanwords)
            'the', 'a', 'an', 'of', 'and', 'or', 'with', 'for',
        );
    }

    /**
     * Readable labels for the document's synthetic fields. Used by the
     * search-type detector in the frontend ("Searching by X").
     *
     * IMPORTANT: `name` is INCLUDED in the dict for key consistency, but the
     * frontend JS IGNORES it on purpose in its detection (it does not show a
     * "Searching by name" chip because that is the default behavior and would
     * only add noise).
     *
     * Labels for extensible attributes come from
     * mibizum_sync_attribute_config.display_label (not here).
     *
     * @return array {synthetic_field_code: readable_label}
     */
    public static function getSyntheticFieldLabels()
    {
        $h = Mage::helper('mibizum_sync');
        return array(
            'name'       => $h->__('Name'),
            'sku'        => 'SKU',
            'categories' => $h->__('Category'),
        );
    }
}
