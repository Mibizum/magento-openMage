<?php
/**
 * Mibizum_Sync 0.6.0 -> 0.6.1 (no-op DB upgrade).
 *
 * Production-ready release. Changes in v0.6.1 (all in CODE, no DB changes):
 *   - SAFE-DISABLE: the catalog observers (save/delete/stock), Cron
 *     (fullReindex/drainQueue), and Worker (processBatch) short-circuit when
 *     `isEnabled()` (connection/enabled + api key + api url) is false. A store
 *     with the module installed but not connected does NOT enqueue, drain, or
 *     touch `mibizum_sync_index_queue`. This resolves the contradiction with
 *     what the admin screen already promised.
 *   - Helper::getNatureBadgesIndex() tolerates missing tables (try/catch ->
 *     empty index) instead of propagating the SQL error to the document render.
 *   - Distribution through all the standard Magento 1 channels: modman,
 *     composer (type=magento-module), two-phase FTP, Magento Connect.
 *   - Clean uninstall script (scripts/uninstall-mibizum-sync.sh).
 *
 * This script exists only so that Magento closes the version chain in
 * core_resource (data version -> 0.6.1). startSetup/endSetup are idempotent.
 *
 * Compatible with PHP 5.4+.
 *
 * @var Mage_Core_Model_Resource_Setup $installer
 */

/** @var Mage_Core_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();
$installer->endSetup();
