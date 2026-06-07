<?php
/**
 * Mibizum_Sync_Block_Frontend_Ingredient
 *
 * Feeds the Smart Item data to the ficha template. Reads it from the registry
 * (`current_smart_item`) set by Mibizum_Sync_IndexController::viewAction after
 * querying the Mibizum SaaS.
 *
 * Compatible with PHP 5.4+.
 */
class Mibizum_Sync_Block_Frontend_Ingredient extends Mage_Core_Block_Template
{
    /** @return array|null */
    public function getSmartItem()
    {
        return Mage::registry('current_smart_item');
    }

    /**
     * URL of the substitute product (if any). `substituteProductId` is assumed
     * to be the SKU of a Magento product; if it does not exist in this store we
     * simply omit the link (the rest of the ficha still renders).
     *
     * @return string|null
     */
    public function getSubstituteUrl()
    {
        $si = $this->getSmartItem();
        if (!$si || empty($si['hasSubstitute']) || empty($si['substituteProductId'])) {
            return null;
        }
        try {
            $product = Mage::getModel('catalog/product')->loadByAttribute('sku', $si['substituteProductId']);
            if ($product && $product->getId()) {
                return $product->getProductUrl();
            }
        } catch (Exception $e) {
            // silent
        }
        return null;
    }

    /**
     * Recent public history (entries with a publicNote only, max 10).
     * @return array
     */
    public function getPublicHistory()
    {
        $si = $this->getSmartItem();
        if (!$si || empty($si['history']) || !is_array($si['history'])) {
            return array();
        }
        $public = array();
        foreach ($si['history'] as $h) {
            if (!empty($h['publicNote'])) {
                $public[] = $h;
                if (count($public) >= 10) break;
            }
        }
        return $public;
    }

    // ---- Listing page (/{url_prefix}/) -------------------------------------

    /** All enabled Smart Items for the listing page. */
    public function getSmartItems()
    {
        return Mage::helper('mibizum_sync/ingredient')->fetchSmartItems(200);
    }

    /** Configurable listing title (Ingredientes / Próximamente / I+D / Raw...). */
    public function getListTitle()
    {
        return Mage::helper('mibizum_sync/ingredient')->getListTitle();
    }

    /** Public ficha URL for a given slug. */
    public function getFichaUrl($slug)
    {
        return Mage::helper('mibizum_sync/ingredient')->getFichaUrl($slug);
    }

    /** Short, plain-text excerpt of a description (the CSS also clamps to 2 lines). */
    public function excerpt($text, $max = 160)
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $text)));
        if (function_exists('mb_strlen')) {
            if (mb_strlen($text) <= $max) return $text;
            return rtrim(mb_substr($text, 0, $max)) . '…';
        }
        if (strlen($text) <= $max) return $text;
        return rtrim(substr($text, 0, $max)) . '…';
    }
}
