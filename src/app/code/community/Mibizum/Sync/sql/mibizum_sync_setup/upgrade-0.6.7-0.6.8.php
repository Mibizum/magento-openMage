<?php
/**
 * Mibizum_Sync 0.6.7 -> 0.6.8 (no-op DB upgrade).
 *
 * Naming only, no DB schema change: the two API key fields in the Connection
 * group are now labelled "Private API key" (the write/indexing key: secret,
 * server-side, regenerate if exposed) and "Public API key" (the read/search key:
 * goes in the browser widget, safe to expose), with their help text and the i18n
 * in all 6 locales updated to match. The config field node names
 * (connection/api_key = private, connection/search_api_key = public) are
 * unchanged, so stored values keep working without migration.
 *
 * Closes the version chain in core_resource (data version -> 0.6.8).
 *
 * Compatible with PHP 5.4+.
 *
 * @var Mage_Core_Model_Resource_Setup $installer
 */

/** @var Mage_Core_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();
$installer->endSetup();
