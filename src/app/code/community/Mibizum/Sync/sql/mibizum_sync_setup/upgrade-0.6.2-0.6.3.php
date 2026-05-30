<?php
/**
 * Mibizum_Sync 0.6.2 -> 0.6.3 (no-op DB upgrade).
 *
 * Code-only change, no DB changes:
 *   - The native-search Enter override (Mibizum_Sync_Model_NativeSearchBridge)
 *     now gates on Helper::isSearchEnabled() (connection/enabled + SEARCH key +
 *     API URL) instead of isEnabled() (which requires the INDEXER key). This
 *     lets a merchant route /catalogsearch/result/ through the engine without
 *     indexing the catalog from Magento (e.g. the catalog is populated by
 *     another source). The indexing side (observers/cron/worker) keeps using
 *     isEnabled() / isEnabledAnywhere() as before.
 *
 * This script exists only so Magento closes the version chain in core_resource
 * (data version -> 0.6.3). startSetup/endSetup are idempotent.
 *
 * Compatible with PHP 5.4+.
 *
 * @var Mage_Core_Model_Resource_Setup $installer
 */

/** @var Mage_Core_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();
$installer->endSetup();
