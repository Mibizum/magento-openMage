<?php
/**
 * Mibizum_Sync_Model_AttributeBadge
 *
 * Informational badge rendered with the product VALUE for a given
 * attribute_code. The admin configures the look (color, icon,
 * position, shape, display_mode); the badge's "visible label" on each
 * hit is the product's real value resolved at runtime by the
 * ProductMapper (e.g. attribute_code=pais_origen and product.pais_origen='China'
 * -> badge label "China").
 *
 * It does not filter or search: it is purely cosmetic/informational.
 *
 * Compatible with PHP 5.4 through 8.x.
 */
class Mibizum_Sync_Model_AttributeBadge extends Mage_Core_Model_Abstract
{
    protected function _construct()
    {
        $this->_init('mibizum_sync/attributeBadge');
    }

    protected function _beforeSave()
    {
        $now = Varien_Date::now();
        if (!$this->getId()) {
            $this->setCreatedAt($now);
        }
        $this->setUpdatedAt($now);

        if (!$this->getColorHex())     $this->setColorHex('#1E9C3C');
        if (!$this->getTextColorHex()) {
            $this->setTextColorHex(Mibizum_Sync_Model_Nature::contrastingTextColor($this->getColorHex()));
        }
        if (!$this->getDisplayMode()) $this->setDisplayMode('icon_and_text');
        if (!$this->getPosition())    $this->setPosition('top_right');
        if (!$this->getShape())       $this->setShape('pill');

        if ($this->getShape() === 'circle') {
            $this->setDisplayMode('icon_only');
        }

        return parent::_beforeSave();
    }
}
