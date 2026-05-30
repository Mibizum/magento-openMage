<?php
/**
 * Mibizum_Sync_Model_NatureCategory
 *
 * M:N assignment between a nature badge and a Magento category.
 * include_descendants=1 (default) means the badge also applies to all
 * descendant subcategories of the assigned category.
 *
 * Compatible with PHP 5.4 through 8.x.
 */
class Mibizum_Sync_Model_NatureCategory extends Mage_Core_Model_Abstract
{
    protected function _construct()
    {
        $this->_init('mibizum_sync/natureCategory');
    }

    protected function _beforeSave()
    {
        if (!$this->getId()) {
            $this->setCreatedAt(Varien_Date::now());
        }
        return parent::_beforeSave();
    }
}
