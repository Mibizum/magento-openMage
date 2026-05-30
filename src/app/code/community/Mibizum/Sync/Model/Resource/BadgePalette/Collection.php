<?php
/**
 * Color palette collection. Ordered by sort_order asc.
 *
 * Compatible with PHP 5.4 through 8.x.
 */
class Mibizum_Sync_Model_Resource_BadgePalette_Collection extends Mage_Core_Model_Resource_Db_Collection_Abstract
{
    protected function _construct()
    {
        $this->_init('mibizum_sync/badgePalette');
        $this->setOrder('sort_order', 'ASC');
    }
}
