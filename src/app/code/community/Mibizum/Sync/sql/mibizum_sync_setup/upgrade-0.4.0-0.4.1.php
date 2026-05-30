<?php
/**
 * Mibizum_Sync upgrade 0.4.0 -> 0.4.1
 *
 * Bug fix: the `text_color_hex` column was added to the Nature model and the
 * admin form (during an earlier refactor plus a font color adjustment), but it
 * was NEVER actually added to the `mibizum_sync_nature_badges` table. The Magento
 * Db Adapter silently filters out unknown columns on INSERT/UPDATE, so save()
 * appeared to work (no exception) but the value was never persisted.
 *
 * Compatible with PHP 5.4+.
 *
 * @var Mage_Core_Model_Resource_Setup $installer
 */

$installer = $this;
$installer->startSetup();

$conn = $installer->getConnection();
$naturesTable = $installer->getTable('mibizum_sync/nature');

if (!$conn->tableColumnExists($naturesTable, 'text_color_hex')) {
    $conn->addColumn($naturesTable, 'text_color_hex', array(
        'type'     => Varien_Db_Ddl_Table::TYPE_TEXT,
        'length'   => 7,
        'nullable' => true,
        'default'  => null,
        'comment'  => 'Badge text color (auto-calculated from luminosity if null)',
        'after'    => 'color_hex',
    ));
}

$installer->endSetup();
