<?php
/**
 * Mibizum_Sync upgrade 0.5.0 -> 0.5.1
 *
 * Extends the FA 4.7 support (added in 0.5.0 for Nature) to the other two
 * badge types for consistency: Attributebadge (custom attributes) and
 * Systemoverride (system). Same `icon_fa_class VARCHAR(64)` column.
 *
 * Compatible with PHP 5.4+.
 *
 * @var Mage_Core_Model_Resource_Setup $installer
 */

$installer = $this;
$installer->startSetup();

$conn = $installer->getConnection();

foreach (array('mibizum_sync/attributeBadge', 'mibizum_sync/systemOverride') as $entityAlias) {
    $tableName = $installer->getTable($entityAlias);
    if ($conn->isTableExists($tableName) && !$conn->tableColumnExists($tableName, 'icon_fa_class')) {
        $conn->addColumn(
            $tableName,
            'icon_fa_class',
            array(
                'type'     => Varien_Db_Ddl_Table::TYPE_TEXT,
                'length'   => 64,
                'nullable' => true,
                'default'  => null,
                'comment'  => 'Font Awesome 4.7 class (e.g. fa-leaf). Renders <i class="fa fa-X"> on the frontend.',
            )
        );
    }
}

$installer->endSetup();
