<?php
/**
 * Mibizum_Sync upgrade 0.6.1 -> 0.6.2
 *
 * Fixes orphaned documents on product deletion. Documents are keyed by SKU
 * (ProductMapper sets 'id' => $sku), but the delete path only stored the
 * Magento entity_id (product_id), so the worker deleted by entity_id - a no-op
 * against an index keyed by SKU. The product is already gone by the time the
 * queue drains, so the SKU is now captured at enqueue time
 * (catalog_product_delete_before) and stored here.
 *
 * Adds a nullable `sku` column to mibizum_sync_index_queue. Only delete entries
 * populate it (upserts already delete-by-SKU via the mapper-null path). Existing
 * delete entries enqueued before this column existed keep a null sku and are
 * drained harmlessly by the worker (they were no-ops before too).
 *
 * Idempotent: guarded with tableColumnExists, so re-running does nothing.
 *
 * Compatible with PHP 5.4+.
 *
 * @var Mage_Core_Model_Resource_Setup $installer
 */

$installer = $this;
$installer->startSetup();

$conn      = $installer->getConnection();
$queueTable = $conn->getTableName('mibizum_sync_index_queue');

if ($conn->isTableExists($queueTable) && !$conn->tableColumnExists($queueTable, 'sku')) {
    $conn->addColumn(
        $queueTable,
        'sku',
        array(
            'type'     => Varien_Db_Ddl_Table::TYPE_TEXT,
            'length'   => 64,
            'nullable' => true,
            'default'  => null,
            'after'    => 'product_id',
            'comment'  => 'SKU captured at enqueue time for deletes (documents are keyed by SKU)',
        )
    );
}

$installer->endSetup();
