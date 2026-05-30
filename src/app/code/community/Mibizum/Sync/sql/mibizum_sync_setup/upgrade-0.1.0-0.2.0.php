<?php
/**
 * Mibizum_Sync upgrade 0.1.0 -> 0.2.0
 *
 * Nature badges: tables to manage visual badges that categorize products by
 * type (Essential Oil, Hydrosol, Aroma, ...) with an M:N mapping to Magento
 * categories.
 *
 * Model:
 *   - mibizum_sync_nature_badges        one badge per nature (label, icon, color)
 *   - mibizum_sync_nature_badge_categories  M:N with catalog_category_entity, with an
 *                                   include_descendants flag to cover the whole
 *                                   branch or only the exact category.
 *
 * Compatible with PHP 5.4+.
 *
 * @var Mage_Core_Model_Resource_Setup $installer
 */

$installer = $this;
$installer->startSetup();

// ---------------------------------------------------------------------------
// Main table: badge definitions
// ---------------------------------------------------------------------------
$naturesTable = $installer->getTable('mibizum_sync/nature');

if (!$installer->getConnection()->isTableExists($naturesTable)) {

    $t = $installer->getConnection()
        ->newTable($naturesTable)
        ->addColumn('id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
            'identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true,
        ), 'Badge ID')
        ->addColumn('label', Varien_Db_Ddl_Table::TYPE_TEXT, 64, array(
            'nullable' => false,
        ), 'Visible text (e.g. Essential Oil)')
        ->addColumn('slug', Varien_Db_Ddl_Table::TYPE_TEXT, 64, array(
            'nullable' => false,
        ), 'Unique URL-safe identifier (auto-generated from label)')
        ->addColumn('icon_svg', Varien_Db_Ddl_Table::TYPE_TEXT, '4M', array(
            'nullable' => true,
        ), 'Inline SVG (preferred). Recolorable with currentColor.')
        ->addColumn('icon_url', Varien_Db_Ddl_Table::TYPE_TEXT, 255, array(
            'nullable' => true,
        ), 'PNG/JPG fallback URL when there is no SVG')
        ->addColumn('color_hex', Varien_Db_Ddl_Table::TYPE_TEXT, 7, array(
            'nullable' => false,
            'default'  => '#1E9C3C',
        ), 'Badge color (green by default)')
        ->addColumn('sort_priority', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, array(
            'unsigned' => true, 'nullable' => false, 'default' => 100,
        ), 'If a product matches several badges, the one with the lowest priority wins')
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
            $installer->getIdxName($naturesTable, array('slug'), Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE),
            array('slug'),
            array('type' => Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE)
        )
        ->addIndex(
            $installer->getIdxName($naturesTable, array('enabled')),
            array('enabled')
        )
        ->setComment('Mibizum_Sync nature badges (visual categorization)');

    $installer->getConnection()->createTable($t);
}

// ---------------------------------------------------------------------------
// M:N table badge <-> Magento category
// ---------------------------------------------------------------------------
$bridgeTable = $installer->getTable('mibizum_sync/natureCategory');

if (!$installer->getConnection()->isTableExists($bridgeTable)) {

    $t = $installer->getConnection()
        ->newTable($bridgeTable)
        ->addColumn('id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
            'identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true,
        ), 'Bridge ID')
        ->addColumn('badge_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
            'unsigned' => true, 'nullable' => false,
        ), 'FK -> mibizum_sync_nature_badges.id')
        ->addColumn('category_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
            'unsigned' => true, 'nullable' => false,
        ), 'Logical FK -> catalog_category_entity.entity_id')
        ->addColumn('include_descendants', Varien_Db_Ddl_Table::TYPE_BOOLEAN, null, array(
            'nullable' => false, 'default' => 1,
        ), 'If 1, the badge also covers the nested subcategories')
        ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, array(
            'nullable' => false,
        ), 'Created at')
        ->addIndex(
            $installer->getIdxName($bridgeTable, array('badge_id', 'category_id'), Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE),
            array('badge_id', 'category_id'),
            array('type' => Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE)
        )
        ->addIndex(
            $installer->getIdxName($bridgeTable, array('category_id')),
            array('category_id')
        )
        ->addForeignKey(
            $installer->getFkName($bridgeTable, 'badge_id', $naturesTable, 'id'),
            'badge_id',
            $naturesTable,
            'id',
            Varien_Db_Ddl_Table::ACTION_CASCADE,
            Varien_Db_Ddl_Table::ACTION_CASCADE
        )
        ->setComment('M:N nature badge <-> Magento category');

    $installer->getConnection()->createTable($t);
}

$installer->endSetup();
