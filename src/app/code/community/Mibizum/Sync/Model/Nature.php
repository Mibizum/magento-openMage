<?php
/**
 * Mibizum_Sync_Model_Nature
 *
 * A category-driven badge entity. Each row of mibizum_sync_nature_badges is a
 * product "type" shown as a visual badge over the hit image (Essential Oil,
 * Hydrosol, Aroma, ...).
 *
 * Covers category badges only. System badges (out of stock, on sale, etc.) have
 * their own model Mibizum_Sync_Model_SystemOverride, with a visual override and
 * the logic/labels in config.
 *
 * Compatible with PHP 5.4 through 8.x.
 */
class Mibizum_Sync_Model_Nature extends Mage_Core_Model_Abstract
{
    /** @var string[] Display modes. */
    const DISPLAY_ICON_ONLY     = 'icon_only';
    const DISPLAY_TEXT_ONLY     = 'text_only';
    const DISPLAY_ICON_AND_TEXT = 'icon_and_text';

    /** @var string[] Positions. */
    const POSITION_TOP_LEFT     = 'top_left';
    const POSITION_TOP_RIGHT    = 'top_right';
    const POSITION_BOTTOM_LEFT  = 'bottom_left';
    const POSITION_BOTTOM_RIGHT = 'bottom_right';
    const POSITION_BELOW_IMAGE  = 'below_image';

    /** @var string[] Shapes. */
    const SHAPE_PILL           = 'pill';
    const SHAPE_CIRCLE         = 'circle';
    const SHAPE_SQUARE_ROUNDED = 'square_rounded';

    protected function _construct()
    {
        $this->_init('mibizum_sync/nature');
    }

    /**
     * Auto-populate timestamps + slug if empty. Applies business rules:
     *   - shape=circle forces display_mode=icon_only (text does not fit).
     */
    protected function _beforeSave()
    {
        $now = Varien_Date::now();
        if (!$this->getId()) {
            $this->setCreatedAt($now);
        }
        $this->setUpdatedAt($now);

        if (!$this->getSlug()) {
            $this->setSlug($this->_generateSlug($this->getLabel()));
        }
        if (!$this->getColorHex()) {
            $this->setColorHex('#1E9C3C');
        }
        // If the admin did not pick a text_color_hex, derive it from the
        // background luminosity. A light background (e.g. #ACD8F1) gets dark text
        // (#3C3C3C) automatically, and a dark background gets light text
        // (#FFFFFF). The admin can override the computed value from the form.
        if (!$this->getTextColorHex()) {
            $this->setTextColorHex(self::contrastingTextColor($this->getColorHex()));
        }
        if (!$this->getDisplayMode()) {
            $this->setDisplayMode(self::DISPLAY_ICON_AND_TEXT);
        }
        if (!$this->getPosition()) {
            $this->setPosition(self::POSITION_BOTTOM_LEFT);
        }
        if (!$this->getShape()) {
            $this->setShape(self::SHAPE_PILL);
        }

        if ($this->getShape() === self::SHAPE_CIRCLE) {
            $this->setDisplayMode(self::DISPLAY_ICON_ONLY);
        }

        return parent::_beforeSave();
    }

    /**
     * Return a readable font color over the given background, computed from
     * relative luminance (YIQ Y formula). Y > 160 = light background -> dark
     * text #3C3C3C. If the hex is invalid, returns a safe gray #555555.
     *
     * Static helper reused by:
     *  - Mibizum_Sync_Model_Nature::_beforeSave (backend default)
     *  - Mibizum_Sync_Model_SystemOverride::_beforeSave (idem)
     *  - controllers/.../NatureController::suggestTextColorAction (AJAX form)
     *
     * @param string $hex Background color as #RRGGBB
     * @return string     Recommended text color as #RRGGBB
     */
    public static function contrastingTextColor($hex)
    {
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $hex)) {
            return '#555555';
        }
        $r = hexdec(substr($hex, 1, 2));
        $g = hexdec(substr($hex, 3, 2));
        $b = hexdec(substr($hex, 5, 2));
        $luma = (0.299 * $r + 0.587 * $g + 0.114 * $b);
        return $luma > 160 ? '#3C3C3C' : '#FFFFFF';
    }

    /**
     * The associated categories (M:N) are removed automatically by the FK
     * cascade defined in upgrade-0.1.0-0.2.0.php.
     */
    protected function _afterDeleteCommit()
    {
        return parent::_afterDeleteCommit();
    }

    /**
     * @param string $label
     * @return string url-safe slug (e.g. "Essential Oil" -> "essential-oil")
     */
    protected function _generateSlug($label)
    {
        $s = trim((string) $label);

        // Native strtolower is NOT UTF-8 aware. Use mb_strtolower when available
        // so "Idéntico" -> "idéntico" without breaking bytes.
        if (function_exists('mb_strtolower')) {
            $s = mb_strtolower($s, 'UTF-8');
        } else {
            $s = strtolower($s);
        }

        // Manual map of common Spanish/European accents. More robust than
        // relying on iconv//TRANSLIT (which fails silently on some PHP-FPM
        // locales). We substitute before the next preg_replace turns any
        // non-[a-z0-9] character into a dash - without this, "Idéntico" would
        // become "id-ntico".
        $replacements = array(
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
            'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
            'ñ' => 'n', 'ç' => 'c',
            // Uppercase, in case mb_strtolower did not apply (fallback).
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u',
            'À' => 'a', 'È' => 'e', 'Ì' => 'i', 'Ò' => 'o', 'Ù' => 'u',
            'Â' => 'a', 'Ê' => 'e', 'Î' => 'i', 'Ô' => 'o', 'Û' => 'u',
            'Ä' => 'a', 'Ë' => 'e', 'Ï' => 'i', 'Ö' => 'o', 'Ü' => 'u',
            'Ñ' => 'n', 'Ç' => 'c',
        );
        $s = strtr($s, $replacements);

        // In case exotic characters remain (Chinese, Arabic, emoji, etc.), try
        // transliteration via iconv as a last resort. If it fails, the non-ASCII
        // chars fall through to the next regex and become dashes.
        if (function_exists('iconv')) {
            $tr = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if ($tr !== false && $tr !== '') {
                $s = $tr;
            }
        }

        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = trim($s, '-');
        return $s !== '' ? $s : 'badge-' . substr(md5(uniqid()), 0, 6);
    }

    /**
     * Categories associated with this badge (with the include_descendants flag).
     *
     * @return Mibizum_Sync_Model_Resource_NatureCategory_Collection
     */
    public function getCategoryAssignments()
    {
        return Mage::getModel('mibizum_sync/natureCategory')
            ->getCollection()
            ->addFieldToFilter('badge_id', $this->getId());
    }
}
