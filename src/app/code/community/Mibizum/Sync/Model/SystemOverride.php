<?php
/**
 * Mibizum_Sync_Model_SystemOverride
 *
 * System badge (stock_out, stock_low, in_offer, new, featured). One row per
 * kind (5 fixed rows, never created or deleted). This table stores the visual
 * look (color_hex, icon, position, shape...).
 *
 * The badge behavior (enabled, visible label, threshold/days) lives in
 * core_config_data (mibizum_sync/badges/*); this model reads it with
 * getVisibleLabel() / isVisibleEnabled(). Both halves are edited together in
 * the System Badges form.
 *
 * Compatible with PHP 5.4 through 8.x.
 */
class Mibizum_Sync_Model_SystemOverride extends Mage_Core_Model_Abstract
{
    const KIND_STOCK_OUT = 'stock_out';
    const KIND_STOCK_LOW = 'stock_low';
    const KIND_IN_OFFER  = 'in_offer';
    const KIND_NEW       = 'new';
    const KIND_FEATURED  = 'featured';

    /** @var string[] Canonical list of kinds. */
    public static $allKinds = array(
        self::KIND_STOCK_OUT,
        self::KIND_STOCK_LOW,
        self::KIND_IN_OFFER,
        self::KIND_NEW,
        self::KIND_FEATURED,
    );

    protected function _construct()
    {
        $this->_init('mibizum_sync/systemOverride');
    }

    protected function _beforeSave()
    {
        $this->setUpdatedAt(Varien_Date::now());
        if (!$this->getColorHex())     $this->setColorHex('#1E9C3C');
        // Default by luminosity - avoids white text on a light background.
        if (!$this->getTextColorHex()) {
            $this->setTextColorHex(Mibizum_Sync_Model_Nature::contrastingTextColor($this->getColorHex()));
        }
        if (!$this->getPosition())     $this->setPosition('top_left');
        if (!$this->getShape())        $this->setShape('pill');
        if (!$this->getDisplayMode())  $this->setDisplayMode('icon_and_text');

        // shape=circle forces display=icon_only (text does not fit).
        if ($this->getShape() === 'circle') {
            $this->setDisplayMode('icon_only');
        }

        return parent::_beforeSave();
    }

    /**
     * @return string Human-readable label for the kind (for the admin listing).
     */
    public function getKindLabel()
    {
        return self::labelFromKind($this->getKind());
    }

    public static function labelFromKind($kind)
    {
        $labels = array(
            self::KIND_STOCK_OUT => Mage::helper('mibizum_sync')->__('Out of stock'),
            self::KIND_STOCK_LOW => Mage::helper('mibizum_sync')->__('Last units'),
            self::KIND_IN_OFFER  => Mage::helper('mibizum_sync')->__('On sale'),
            self::KIND_NEW       => Mage::helper('mibizum_sync')->__('New'),
            self::KIND_FEATURED  => Mage::helper('mibizum_sync')->__('Featured'),
        );
        return isset($labels[$kind]) ? $labels[$kind] : $kind;
    }

    /**
     * Canonical kind (DB) -> prefix of the mibizum_sync/badges/* config keys.
     *
     * The kinds are the short canonical identifiers (stock_out, stock_low,
     * in_offer, new, featured). The config keys use a different historical
     * convention (out_of_stock_label, low_stock_label, ...). This table is the
     * single source of truth for that mapping.
     *
     * @return array<string,string>
     */
    public static function kindToConfigPrefixMap()
    {
        return array(
            self::KIND_STOCK_OUT => 'out_of_stock',
            self::KIND_STOCK_LOW => 'low_stock',
            self::KIND_IN_OFFER  => 'in_offer',
            self::KIND_NEW       => 'new',
            self::KIND_FEATURED  => 'featured',
        );
    }

    /**
     * @return string Prefix under mibizum_sync/badges/ for this kind.
     */
    public function getConfigPrefix()
    {
        $map = self::kindToConfigPrefixMap();
        $kind = (string) $this->getKind();
        return isset($map[$kind]) ? $map[$kind] : $kind;
    }

    /**
     * Read the badge's visible text from config (mibizum_sync/badges/*).
     * Used by the storefront when rendering the actual badge.
     */
    public function getVisibleLabel()
    {
        $cfgKey = 'mibizum_sync/badges/' . $this->getConfigPrefix() . '_label';
        $val = Mage::getStoreConfig($cfgKey);
        return $val ? (string) $val : $this->getKindLabel();
    }

    /**
     * @return bool The badge "enabled" flag (mibizum_sync/badges/*).
     */
    public function isVisibleEnabled()
    {
        $cfgKey = 'mibizum_sync/badges/' . $this->getConfigPrefix() . '_enabled';
        return (bool) Mage::getStoreConfigFlag($cfgKey);
    }
}
