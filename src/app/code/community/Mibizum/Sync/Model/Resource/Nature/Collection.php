<?php
/**
 * Nature badge collection. Used by the admin grid and the helper that
 * resolves "which badge applies to this product".
 *
 * Compatible with PHP 5.4 through 8.x.
 */
class Mibizum_Sync_Model_Resource_Nature_Collection extends Mage_Core_Model_Resource_Db_Collection_Abstract
{
    protected function _construct()
    {
        $this->_init('mibizum_sync/nature');
    }

    /**
     * Only enabled badges, ordered by sort_priority asc (lower = higher priority).
     *
     * @return $this
     */
    public function addEnabledFilter()
    {
        $this->addFieldToFilter('enabled', 1);
        $this->setOrder('sort_priority', 'ASC');
        return $this;
    }
}
