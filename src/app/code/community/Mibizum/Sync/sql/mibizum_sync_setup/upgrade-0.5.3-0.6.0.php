<?php
/**
 * Mibizum_Sync 0.5.3 -> 0.6.0 - no-op DB upgrade.
 *
 * Changes in v0.6.0:
 *   - NEW: Model/Search/Adapter.php (server-side HTTP client to the Mibizum
 *     /api/v1/search endpoint).
 *   - NEW: Model/NativeSearchBridge.php (override of Magento's native fulltext
 *     to route to the Mibizum search engine, with a MySQL fallback if Mibizum
 *     returns 5xx).
 *   - NEW: Helper/Data.php::getSearchApiKey() + getSearchEngineParams()
 *     (60s cache against /api/v1/runtime-config).
 *   - NEW admin config: mibizum_sync/connection/search_api_key (scope=search).
 *   - NEW config.xml: <catalogsearch_resource><rewrite> redirects
 *     `Mage_CatalogSearch_Model_Resource_Fulltext` to the Mibizum subclass.
 *
 * No DB TABLES are added, modified, or dropped (all changes live in code +
 * core_config_data). This upgrade script exists only so that Magento closes the
 * version chain (0.5.0 -> 0.6.0) in core_resource.
 *
 * Compatible with PHP 5.4+.
 */

/** @var Mage_Core_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();
$installer->endSetup();
