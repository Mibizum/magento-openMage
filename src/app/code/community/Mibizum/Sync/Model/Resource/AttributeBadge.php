<?php
/**
 * Resource model for AttributeBadge.
 *
 * Compatible with PHP 5.4 through 8.x.
 */
class Mibizum_Sync_Model_Resource_AttributeBadge extends Mage_Core_Model_Resource_Db_Abstract
{
    protected function _construct()
    {
        $this->_init('mibizum_sync/attributeBadge', 'id');
    }
}
