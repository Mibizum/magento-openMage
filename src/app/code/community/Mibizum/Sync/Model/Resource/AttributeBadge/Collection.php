<?php
/**
 * Collection for the AttributeBadge model.
 *
 * Compatible with PHP 5.4 through 8.x.
 */
class Mibizum_Sync_Model_Resource_AttributeBadge_Collection extends Mage_Core_Model_Resource_Db_Collection_Abstract
{
    protected function _construct()
    {
        $this->_init('mibizum_sync/attributeBadge');
        $this->setOrder('sort_priority', 'ASC');
    }
}
