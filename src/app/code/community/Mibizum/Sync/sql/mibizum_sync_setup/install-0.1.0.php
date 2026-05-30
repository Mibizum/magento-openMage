<?php
/**
 * Install script Mibizum_Sync 0.1.0.
 *
 * Creates the mibizum_sync_attribute_config table to store the configuration
 * of which catalog attributes are indexed, searchable, filterable, etc.
 *
 * The native Magento flags (catalog_eav_attribute) are NOT touched - this is
 * the source of truth for Mibizum_Sync, kept separate for safety (so as not to
 * expose layered navigation).
 *
 * Compatible with PHP 5.4+.
 *
 * @var Mage_Core_Model_Resource_Setup $installer
 */

$installer = $this;
$installer->startSetup();

$tableName = $installer->getTable('mibizum_sync/attributeConfig');

if (!$installer->getConnection()->isTableExists($tableName)) {

    $table = $installer->getConnection()
        ->newTable($tableName)
        ->addColumn('config_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
            'identity' => true,
            'unsigned' => true,
            'nullable' => false,
            'primary'  => true,
        ), 'Config ID')
        ->addColumn('attribute_code', Varien_Db_Ddl_Table::TYPE_TEXT, 64, array(
            'nullable' => false,
        ), 'Attribute code (e.g. num_cas, inci, lote_externo)')
        ->addColumn('display_label', Varien_Db_Ddl_Table::TYPE_TEXT, 128, array(
            'nullable' => false,
        ), 'Label shown to the customer')
        ->addColumn('is_searchable', Varien_Db_Ddl_Table::TYPE_BOOLEAN, null, array(
            'nullable' => false,
            'default'  => 0,
        ), 'Index for fulltext search')
        ->addColumn('is_filterable', Varien_Db_Ddl_Table::TYPE_BOOLEAN, null, array(
            'nullable' => false,
            'default'  => 0,
        ), 'Expose as a facet')
        ->addColumn('is_sortable', Varien_Db_Ddl_Table::TYPE_BOOLEAN, null, array(
            'nullable' => false,
            'default'  => 0,
        ), 'Allow sorting by this field')
        ->addColumn('facet_type', Varien_Db_Ddl_Table::TYPE_TEXT, 16, array(
            'nullable' => true,
        ), 'dropdown|multiselect|range|boolean')
        ->addColumn('searchable_boost', Varien_Db_Ddl_Table::TYPE_DECIMAL, '4,2', array(
            'nullable' => false,
            'default'  => '1.00',
        ), 'Relative weight in the search ranking')
        ->addColumn('display_order', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, array(
            'unsigned' => true,
            'nullable' => false,
            'default'  => 100,
        ), 'Presentation order in filters')
        ->addColumn('enabled', Varien_Db_Ddl_Table::TYPE_BOOLEAN, null, array(
            'nullable' => false,
            'default'  => 1,
        ), 'Configuration active')
        ->addColumn('notes', Varien_Db_Ddl_Table::TYPE_TEXT, '64k', array(
            'nullable' => true,
        ), 'Internal notes for future maintainers')
        ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, array(
            'nullable' => false,
            'default'  => Varien_Db_Ddl_Table::TIMESTAMP_INIT,
        ), 'Created at')
        ->addColumn('updated_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, array(
            'nullable' => true,
        ), 'Updated at')
        ->addIndex(
            $installer->getIdxName($tableName, array('attribute_code'), Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE),
            array('attribute_code'),
            array('type' => Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE)
        )
        ->setComment('Mibizum_Sync - indexable attribute configuration');

    $installer->getConnection()->createTable($table);
}

// ---------------------------------------------------------------------------
// Table mibizum_sync_index_queue - queue of indexing operations.
//
// The observer enqueues entries; the worker (cron + on-demand) processes them
// in batches toward the search engine. Allows debouncing, retries, and isolation
// from the admin save flow.
// ---------------------------------------------------------------------------

$queueTable = $installer->getConnection()->getTableName('mibizum_sync_index_queue');

if (!$installer->getConnection()->isTableExists($queueTable)) {

    $table = $installer->getConnection()
        ->newTable($queueTable)
        ->addColumn('queue_id', Varien_Db_Ddl_Table::TYPE_BIGINT, null, array(
            'identity' => true,
            'unsigned' => true,
            'nullable' => false,
            'primary'  => true,
        ), 'Queue ID')
        ->addColumn('operation', Varien_Db_Ddl_Table::TYPE_TEXT, 16, array(
            'nullable' => false,
        ), 'upsert | delete')
        ->addColumn('product_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
            'unsigned' => true,
            'nullable' => false,
        ), 'Magento entity_id')
        ->addColumn('reason', Varien_Db_Ddl_Table::TYPE_TEXT, 64, array(
            'nullable' => true,
        ), 'product_save | stock_change | manual | full_reindex')
        ->addColumn('enqueued_at', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, array(
            'nullable' => false,
            'default'  => Varien_Db_Ddl_Table::TIMESTAMP_INIT,
        ), 'Enqueued at')
        ->addColumn('locked_at', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, array(
            'nullable' => true,
        ), 'Locked at (worker holding)')
        ->addColumn('locked_by', Varien_Db_Ddl_Table::TYPE_TEXT, 64, array(
            'nullable' => true,
        ), 'Worker token holding the lock')
        ->addColumn('attempts', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, array(
            'unsigned' => true,
            'nullable' => false,
            'default'  => 0,
        ), 'Number of processing attempts')
        ->addColumn('last_error', Varien_Db_Ddl_Table::TYPE_TEXT, '64k', array(
            'nullable' => true,
        ), 'Last error message')
        ->addIndex(
            $installer->getIdxName($queueTable, array('product_id', 'operation'), Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE),
            array('product_id', 'operation'),
            array('type' => Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE)
        )
        ->addIndex(
            $installer->getIdxName($queueTable, array('locked_at')),
            array('locked_at')
        )
        ->setComment('Mibizum_Sync - queue of pending indexing operations');

    $installer->getConnection()->createTable($table);
}

// No attributes are seeded on a fresh install. The module ships with an empty
// indexable-attribute configuration; the merchant adds the catalog attributes
// they want to index from Mibizum > Search > Indexable attributes. Only the
// synthetic document fields (name, sku, categories, price, ...) are always
// available.

$installer->endSetup();
