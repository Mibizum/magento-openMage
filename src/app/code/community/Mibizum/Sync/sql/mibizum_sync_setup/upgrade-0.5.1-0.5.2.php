<?php
/**
 * Mibizum_Sync upgrade 0.5.1 -> 0.5.2
 *
 * N:N bridge table between attribute_badge and categories to limit the scope
 * of each attribute_badge. Use case: the attribute badge `pais_origen` (country
 * of origin) is relevant in some categories but not in others, where it may
 * confuse the user. Without a category filter, the badge shows on ALL products
 * that have the attribute, which is a lot of noise.
 *
 * Designed the same way as mibizum_sync_nature_badge_categories: bridge + an
 * include_descendants flag. No entries for a badge => no filter (backward
 * compatible: existing badges keep appearing in every category until the admin
 * explicitly restricts them).
 *
 * Compatible with PHP 5.4+.
 *
 * @var Mage_Core_Model_Resource_Setup $installer
 */

$installer = $this;
$installer->startSetup();

$conn = $installer->getConnection();
$tableName = $installer->getTable('mibizum_sync/attributeBadgeCategory');

if (!$conn->isTableExists($tableName)) {
    $t = $conn->newTable($tableName)
        ->addColumn('id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
            'identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true,
        ), 'ID')
        ->addColumn('badge_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
            'unsigned' => true, 'nullable' => false,
        ), 'Attribute badge ID (FK to mibizum_sync_attribute_badges.id)')
        ->addColumn('category_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
            'unsigned' => true, 'nullable' => false,
        ), 'Magento catalog_category_entity.entity_id')
        ->addColumn('include_descendants', Varien_Db_Ddl_Table::TYPE_BOOLEAN, null, array(
            'nullable' => false, 'default' => 0,
        ), 'If 1, also applies the filter to the category descendants')
        ->addIndex(
            $installer->getIdxName($tableName, array('badge_id', 'category_id'), Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE),
            array('badge_id', 'category_id'),
            array('type' => Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE)
        )
        ->addIndex($installer->getIdxName($tableName, array('badge_id')), array('badge_id'))
        ->addIndex($installer->getIdxName($tableName, array('category_id')), array('category_id'))
        ->addForeignKey(
            $installer->getFkName($tableName, 'badge_id', 'mibizum_sync/attributeBadge', 'id'),
            'badge_id',
            $installer->getTable('mibizum_sync/attributeBadge'),
            'id',
            Varien_Db_Ddl_Table::ACTION_CASCADE
        )
        ->addForeignKey(
            $installer->getFkName($tableName, 'category_id', 'catalog/category', 'entity_id'),
            'category_id',
            $installer->getTable('catalog/category'),
            'entity_id',
            Varien_Db_Ddl_Table::ACTION_CASCADE
        )
        ->setComment('Bridge attribute_badge <-> category (scope filter by category).');

    $conn->createTable($t);
}

$installer->endSetup();
