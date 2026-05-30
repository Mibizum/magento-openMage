<?php
/**
 * Mibizum_Sync_Model_BadgePalette
 *
 * Each row of mibizum_sync_badge_palette is a predefined color selectable
 * from the Badge form dropdown.
 *
 * Compatible with PHP 5.4 through 8.x.
 */
class Mibizum_Sync_Model_BadgePalette extends Mage_Core_Model_Abstract
{
    protected function _construct()
    {
        $this->_init('mibizum_sync/badgePalette');
    }

    protected function _beforeSave()
    {
        if (!$this->getId()) {
            $this->setCreatedAt(Varien_Date::now());
        }
        if (!$this->getTextColorHex()) {
            $this->setTextColorHex('#FFFFFF');
        }
        return parent::_beforeSave();
    }
}
