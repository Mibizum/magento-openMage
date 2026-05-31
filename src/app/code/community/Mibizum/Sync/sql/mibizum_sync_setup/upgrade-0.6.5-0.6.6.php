<?php
/**
 * Mibizum_Sync 0.6.5 -> 0.6.6 (no-op DB upgrade).
 *
 * Admin-only cleanup, no DB schema change:
 *   - Removed the duplicate "Debug mode" toggle from the `general` group in
 *     system.xml (the code only reads connection/debug_mode; the general one
 *     was a dead control) and dropped the stale `general/data_source_slug`
 *     default from config.xml (the real field is connection/data_source_slug).
 *   - The search-input selector field help now states the real default
 *     (`#search`) instead of the never-shipped `#mibizum-search-input`.
 *
 * Any leftover `mibizum_sync/general/debug_mode` or
 * `mibizum_sync/general/data_source_slug` rows in core_config_data are harmless
 * (no longer read) and are removed by the uninstaller's `mibizum_sync/%` delete.
 *
 * Closes the version chain in core_resource (data version -> 0.6.6).
 *
 * Compatible with PHP 5.4+.
 *
 * @var Mage_Core_Model_Resource_Setup $installer
 */

/** @var Mage_Core_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();
$installer->endSetup();
