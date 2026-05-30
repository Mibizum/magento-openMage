<?php
/**
 * Mibizum_Sync upgrade 0.4.1 -> 0.4.2
 *
 * Create the `mibizum_sync_attribute_badges` table for informational badges
 * based on custom product attributes. The admin chooses the attribute_code and
 * the visual config; the ProductMapper resolves the product's VALUE at runtime
 * and puts it as the badge label in `attribute_badges` of the index document.
 * That is why there is no value configured here - it comes from the product.
 *
 * UNIQUE(attribute_code) - a single badge per attribute. If the admin wants two
 * badges for the same attribute, this needs a rethink (a possible future
 * feature: variants by option value).
 *
 * Compatible with PHP 5.4+.
 *
 * @var Mage_Core_Model_Resource_Setup $installer
 */

$installer = $this;
$installer->startSetup();

$conn = $installer->getConnection();
$tableName = $installer->getTable('mibizum_sync/attributeBadge');

if (!$conn->isTableExists($tableName)) {
    $t = $conn->newTable($tableName)
        ->addColumn('id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
            'identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true,
        ), 'ID')
        ->addColumn('attribute_code', Varien_Db_Ddl_Table::TYPE_TEXT, 64, array(
            'nullable' => false,
        ), 'attribute_code from eav_attribute (e.g. pais_origen, certificacion)')
        ->addColumn('label', Varien_Db_Ddl_Table::TYPE_TEXT, 64, array(
            'nullable' => true,
        ), 'Fallback label if the product has no value (rare). Default: empty.')
        ->addColumn('color_hex', Varien_Db_Ddl_Table::TYPE_TEXT, 7, array(
            'nullable' => false,
            'default'  => '#1E9C3C',
        ), 'Background color')
        ->addColumn('text_color_hex', Varien_Db_Ddl_Table::TYPE_TEXT, 7, array(
            'nullable' => true,
            'default'  => null,
        ), 'Text color (auto-luminosity if null)')
        ->addColumn('icon_svg', Varien_Db_Ddl_Table::TYPE_TEXT, '4M', array(
            'nullable' => true,
        ), 'Inline SVG. Recolorable with currentColor.')
        ->addColumn('icon_url', Varien_Db_Ddl_Table::TYPE_TEXT, 255, array(
            'nullable' => true,
        ), 'PNG/JPG fallback URL when there is no SVG')
        ->addColumn('position', Varien_Db_Ddl_Table::TYPE_TEXT, 20, array(
            'nullable' => false,
            'default'  => 'top_right',
        ), 'top_left|top_right|bottom_left|bottom_right|below_image')
        ->addColumn('shape', Varien_Db_Ddl_Table::TYPE_TEXT, 16, array(
            'nullable' => false,
            'default'  => 'pill',
        ), 'pill|circle|square_rounded')
        ->addColumn('display_mode', Varien_Db_Ddl_Table::TYPE_TEXT, 16, array(
            'nullable' => false,
            'default'  => 'icon_and_text',
        ), 'icon_only|text_only|icon_and_text')
        ->addColumn('sort_priority', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, array(
            'unsigned' => true, 'nullable' => false, 'default' => 100,
        ), 'For the same corner, the lowest priority wins')
        ->addColumn('enabled', Varien_Db_Ddl_Table::TYPE_BOOLEAN, null, array(
            'nullable' => false, 'default' => 1,
        ), 'Active')
        ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, array(
            'nullable' => false,
        ), 'Created at')
        ->addColumn('updated_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, array(
            'nullable' => false,
        ), 'Updated at')
        ->addIndex(
            $installer->getIdxName($tableName, array('attribute_code'), Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE),
            array('attribute_code'),
            array('type' => Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE)
        )
        ->addIndex(
            $installer->getIdxName($tableName, array('enabled')),
            array('enabled')
        )
        ->setComment('Mibizum_Sync informational badges based on the value of a custom attribute');

    $conn->createTable($t);
}

$installer->endSetup();
