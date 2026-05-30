<?php
/**
 * Mibizum_Sync_Model_Indexer_Queue
 *
 * Indexing operation queue. The observer enqueues catalog events
 * (upsert/delete) and the worker processes them in batches.
 *
 * Design:
 *  - UNIQUE (product_id, operation): if a product is saved 5 times in a row,
 *    only one pending "upsert" entry remains (implicit debouncing via dedup).
 *  - upsert vs delete are disjoint operations: if a product comes in as a
 *    delete after being an upsert, it is overwritten to delete.
 *  - optimistic locking: the worker reserves entries with UPDATE locked_at
 *    WHERE NULL.
 *
 * Compatible with PHP 5.4 through 8.x.
 */
class Mibizum_Sync_Model_Indexer_Queue
{
    const OP_UPSERT = 'upsert';
    const OP_DELETE = 'delete';

    /** @var int Visibility timeout in seconds (entry becomes available again if the worker dies). */
    protected $_lockTimeoutSeconds = 300;

    /** @return Varien_Db_Adapter_Interface */
    protected function _getDb()
    {
        return Mage::getSingleton('core/resource')->getConnection('core_write');
    }

    /** @return string Queue table name. */
    protected function _getTable()
    {
        return Mage::getSingleton('core/resource')->getTableName('mibizum_sync_index_queue');
    }

    /**
     * Enqueue an upsert for a product. If a pending entry already exists for the
     * same product and operation, its reason and enqueued_at are refreshed
     * (locks are kept; entries in progress are not released).
     *
     * @param int    $productId
     * @param string $reason
     * @return void
     */
    public function enqueueProductUpdate($productId, $reason = 'product_save')
    {
        $this->_enqueue($productId, self::OP_UPSERT, $reason);

        // If there was a previous delete entry it no longer applies: remove it.
        $this->_removeOpposite($productId, self::OP_DELETE);
    }

    /**
     * Enqueue a deletion. If there was a pending upsert, it is removed (do not
     * process a product that is about to be deleted).
     *
     * The SKU MUST be captured here (from the catalog_product_delete_before
     * observer, while the product still exists): documents are keyed by SKU, the
     * product is gone by the time the queue drains, and deleting by entity_id is
     * a no-op against the SKU-keyed index.
     *
     * @param int    $productId
     * @param string $sku
     * @param string $reason
     * @return void
     */
    public function enqueueProductDelete($productId, $sku, $reason = 'product_delete')
    {
        $this->_enqueue($productId, self::OP_DELETE, $reason, $sku);
        $this->_removeOpposite($productId, self::OP_UPSERT);
    }

    /**
     * Claim (lock) up to $batchSize pending entries that are NOT already locked.
     * Returns the list to process.
     *
     * @param int    $batchSize
     * @param string $workerToken Unique worker identifier (PID + hostname).
     * @return array Each item: array(queue_id, operation, product_id, reason, attempts).
     */
    public function claimBatch($batchSize, $workerToken)
    {
        $db = $this->_getDb();
        $table = $this->_getTable();

        // 1. Recycle expired locks (worker died without releasing).
        $expired = date('Y-m-d H:i:s', time() - $this->_lockTimeoutSeconds);
        $db->update(
            $table,
            array('locked_at' => null, 'locked_by' => null),
            array('locked_at IS NOT NULL AND locked_at < ?' => $expired)
        );

        // 2. Select unlocked candidates.
        $select = $db->select()
            ->from($table, array('queue_id'))
            ->where('locked_at IS NULL')
            ->order('enqueued_at ASC')
            ->limit((int) $batchSize);

        $ids = $db->fetchCol($select);
        if (empty($ids)) {
            return array();
        }

        // 3. Reserve.
        $db->update(
            $table,
            array(
                'locked_at' => Varien_Date::now(),
                'locked_by' => $workerToken,
                'attempts'  => new Zend_Db_Expr('attempts + 1'),
            ),
            array(
                'queue_id IN (?)'   => $ids,
                'locked_at IS NULL' => null,
            )
        );

        // 4. Return the reserved entries with their data.
        $reserved = $db->fetchAll(
            $db->select()
                ->from($table, array('queue_id', 'operation', 'product_id', 'sku', 'reason', 'attempts'))
                ->where('queue_id IN (?)', $ids)
                ->where('locked_by = ?', $workerToken)
        );

        return $reserved;
    }

    /**
     * Mark entries as completed (removes them from the queue).
     *
     * @param array $queueIds
     * @return int number of rows deleted.
     */
    public function complete(array $queueIds)
    {
        if (empty($queueIds)) {
            return 0;
        }
        return $this->_getDb()->delete($this->_getTable(), array('queue_id IN (?)' => $queueIds));
    }

    /**
     * Mark as failed: release the lock so it retries and store the error.
     *
     * @param array  $queueIds
     * @param string $error
     * @param int    $maxAttempts If exceeded, the entry is discarded.
     * @return int Number of entries discarded (permanently failed).
     */
    public function fail(array $queueIds, $error, $maxAttempts = 5)
    {
        if (empty($queueIds)) {
            return 0;
        }
        $db = $this->_getDb();
        $table = $this->_getTable();

        // Discard the ones that already exceeded maxAttempts.
        $discarded = $db->delete(
            $table,
            array(
                'queue_id IN (?)' => $queueIds,
                'attempts >= ?'   => (int) $maxAttempts,
            )
        );

        // Release the rest for retry.
        $db->update(
            $table,
            array(
                'locked_at'  => null,
                'locked_by'  => null,
                'last_error' => substr((string) $error, 0, 64000),
            ),
            array(
                'queue_id IN (?)' => $queueIds,
                'attempts < ?'    => (int) $maxAttempts,
            )
        );

        return $discarded;
    }

    /**
     * Enqueue a large batch of products (full reindex).
     * Uses INSERT ... ON DUPLICATE in bulk so the table is not hammered.
     *
     * @param array  $productIds
     * @param string $reason
     * @return int number of rows inserted.
     */
    public function enqueueBulkUpsert(array $productIds, $reason = 'full_reindex')
    {
        if (empty($productIds)) {
            return 0;
        }
        $db = $this->_getDb();
        $table = $this->_getTable();
        $now = Varien_Date::now();

        $rows = array();
        foreach ($productIds as $pid) {
            $rows[] = array(
                'operation'   => self::OP_UPSERT,
                'product_id'  => (int) $pid,
                'reason'      => $reason,
                'enqueued_at' => $now,
            );
        }

        return $db->insertOnDuplicate(
            $table,
            $rows,
            array('reason', 'enqueued_at')
        );
    }

    /**
     * Queue statistics for the admin panel / health endpoint.
     *
     * @return array {pending: int, locked: int, oldest_pending_seconds: int|null}
     */
    public function getStats()
    {
        $db = $this->_getDb();
        $table = $this->_getTable();

        $row = $db->fetchRow(
            "SELECT
                COUNT(*)                                                              AS total,
                SUM(CASE WHEN locked_at IS NULL THEN 1 ELSE 0 END)                    AS pending,
                SUM(CASE WHEN locked_at IS NOT NULL THEN 1 ELSE 0 END)                AS locked,
                MIN(CASE WHEN locked_at IS NULL THEN UNIX_TIMESTAMP(enqueued_at) END) AS oldest_ts
             FROM " . $table
        );

        $oldestSec = null;
        if (!empty($row['oldest_ts'])) {
            $oldestSec = max(0, time() - (int) $row['oldest_ts']);
        }

        return array(
            'total'                  => (int) $row['total'],
            'pending'                => (int) $row['pending'],
            'locked'                 => (int) $row['locked'],
            'oldest_pending_seconds' => $oldestSec,
        );
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    protected function _enqueue($productId, $operation, $reason, $sku = null)
    {
        $row = array(
            'operation'   => $operation,
            'product_id'  => (int) $productId,
            'reason'      => $reason,
            'enqueued_at' => Varien_Date::now(),
        );
        $updateCols = array('reason', 'enqueued_at');

        // Only deletes carry a SKU (captured pre-delete). Refresh it on dedup so
        // a re-enqueued delete keeps the latest SKU.
        if ($sku !== null) {
            $row['sku'] = (string) $sku;
            $updateCols[] = 'sku';
        }

        $this->_getDb()->insertOnDuplicate(
            $this->_getTable(),
            $row,
            $updateCols
        );
    }

    protected function _removeOpposite($productId, $operation)
    {
        $this->_getDb()->delete(
            $this->_getTable(),
            array(
                'product_id = ?' => (int) $productId,
                'operation  = ?' => $operation,
                'locked_at IS NULL' => null,
            )
        );
    }
}
