<?php
/**
 * Mibizum_Sync 0.7.0 -> 0.7.1 (no-op DB upgrade).
 *
 * Search result images now use Magento's catalog image helper to index a
 * cached/resized storefront derivative instead of the raw original media URL.
 * Nothing changes in the database; this only closes the version chain in
 * core_resource (data version -> 0.7.1).
 *
 * Compatible with PHP 5.4+.
 *
 * @var Mage_Core_Model_Resource_Setup $installer
 */

/** @var Mage_Core_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();
$installer->endSetup();
