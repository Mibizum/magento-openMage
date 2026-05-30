<?php
/**
 * Mibizum_Sync upgrade 0.4.2 -> 0.5.0
 *
 * Adds the `icon_fa_class` column to `mibizum_sync_nature_badges` to support
 * Font Awesome 4.7 icons (assumed already loaded on the storefront).
 *
 * The admin form will have an autocomplete with the 786 available FA icons.
 * The frontend renders `<i class="fa fa-leaf"></i>` when the badge has
 * `icon_fa_class` populated. The field is optional - it coexists with icon_svg
 * and icon_url. Render priority order: inline SVG > icon_url > FA > placeholder.
 *
 * Compatible with PHP 5.4+.
 *
 * @var Mage_Core_Model_Resource_Setup $installer
 */

$installer = $this;
$installer->startSetup();

$conn = $installer->getConnection();
$tableName = $installer->getTable('mibizum_sync/nature');

if ($conn->isTableExists($tableName) && !$conn->tableColumnExists($tableName, 'icon_fa_class')) {
    $conn->addColumn(
        $tableName,
        'icon_fa_class',
        array(
            'type'     => Varien_Db_Ddl_Table::TYPE_TEXT,
            'length'   => 64,
            'nullable' => true,
            'default'  => null,
            'comment'  => 'Font Awesome 4.7 class (e.g. fa-leaf). Renders <i class="fa fa-X"> on the frontend when populated.',
        )
    );
}

$installer->endSetup();
