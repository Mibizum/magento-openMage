<?php
/**
 * Mibizum_Sync 0.6.10 -> 0.7.1 (no-op DB upgrade).
 *
 * Production stores can upgrade directly from 0.6.10 to this patch line. The
 * 0.7.x changes are code/config only for this path; this script closes the
 * version chain in core_resource (data version -> 0.7.1).
 *
 * Compatible with PHP 5.4+.
 *
 * @var Mage_Core_Model_Resource_Setup $installer
 */

/** @var Mage_Core_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();
$installer->endSetup();
