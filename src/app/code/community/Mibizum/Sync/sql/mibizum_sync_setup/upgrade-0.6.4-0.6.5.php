<?php
/**
 * Mibizum_Sync 0.6.4 -> 0.6.5 (no-op DB upgrade).
 *
 * Code-only fixes, no DB changes:
 *   - ReindexController::statsAction stopped calling the removed
 *     Helper::getSearchIndexName() (it threw, so the Reindex panel and the
 *     install-wizard counter showed "0 products in the index" even when the
 *     catalog was fully indexed). getStats() ignores the index name anyway.
 *   - Search_Adapter clamps the default limit to MAX_LIMIT (50); the previous
 *     default of 60 was rejected by the backend with HTTP 400 invalid_params.
 *   - The wizard indexing step shows an indeterminate loader + live counts
 *     instead of a misleading full bar.
 *
 * Closes the version chain in core_resource (data version -> 0.6.5).
 *
 * Compatible with PHP 5.4+.
 *
 * @var Mage_Core_Model_Resource_Setup $installer
 */

/** @var Mage_Core_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();
$installer->endSetup();
