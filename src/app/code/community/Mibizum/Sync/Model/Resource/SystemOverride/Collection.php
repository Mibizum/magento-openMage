<?php
/**
 * Collection of system badge visual overrides.
 *
 * Compatible with PHP 5.4 through 8.x.
 */
class Mibizum_Sync_Model_Resource_SystemOverride_Collection extends Mage_Core_Model_Resource_Db_Collection_Abstract
{
    protected function _construct()
    {
        $this->_init('mibizum_sync/systemOverride');
        $this->setOrder('sort_priority', 'ASC');
    }
}
