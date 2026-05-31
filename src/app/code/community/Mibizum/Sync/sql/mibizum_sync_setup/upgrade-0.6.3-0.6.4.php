<?php
/**
 * Mibizum_Sync 0.6.3 -> 0.6.4 (no-op DB upgrade).
 *
 * Code-only change, no DB changes:
 *   - First-run install wizard. The wizard state lives in core_config_data
 *     (mibizum_sync/wizard/state) and is created on demand via saveConfig, so
 *     no schema change is required. The overlay is inert on stores that are
 *     already connected (Helper::isEnabled()) or where the wizard is done /
 *     dismissed.
 *
 * This script exists only so Magento closes the version chain in core_resource
 * (data version -> 0.6.4). startSetup/endSetup are idempotent.
 *
 * Compatible with PHP 5.4+.
 *
 * @var Mage_Core_Model_Resource_Setup $installer
 */

/** @var Mage_Core_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();
$installer->endSetup();
