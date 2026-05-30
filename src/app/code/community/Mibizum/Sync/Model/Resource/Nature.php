<?php
/**
 * Resource model for Nature. Persists against the mibizum_sync_nature_badges table.
 *
 * Compatible with PHP 5.4 through 8.x.
 */
class Mibizum_Sync_Model_Resource_Nature extends Mage_Core_Model_Resource_Db_Abstract
{
    protected function _construct()
    {
        $this->_init('mibizum_sync/nature', 'id');
    }
}
