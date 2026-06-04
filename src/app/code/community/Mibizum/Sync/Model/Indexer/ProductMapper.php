<?php
/**
 * Mibizum_Sync_Model_Indexer_ProductMapper
 *
 * Converts a Mage_Catalog_Model_Product into a JSON-friendly document for the
 * search engine. Reads the attribute configuration from
 * mibizum_sync_attribute_config (it does not touch Magento's manager for safety).
 *
 * Returns null when the product must NOT be indexed (disabled, not visible, or
 * out of stock with the respective flag).
 *
 * Compatible with PHP 5.4 through 8.x.
 */
class Mibizum_Sync_Model_Indexer_ProductMapper
{
    /** @var array Static cache of the attribute config {attribute_code => row}. */
    protected static $_attrConfigCache = null;

    /**
     * @param Mage_Catalog_Model_Product $product
     * @return array|null Document ready to send to the search engine, or null if skipped.
     */
    public function map($product)
    {
        if (!$product || !$product->getId()) {
            return null;
        }

        if ((int) $product->getStatus() === Mage_Catalog_Model_Product_Status::STATUS_DISABLED) {
            return null;
        }

        // Indexable types. A catalog can have many grouped products (oil + bottle
        // combos, books with add-ons), bundles (kits), plus simple and
        // configurable. Virtual products (downloadable, gift cards) are excluded
        // by default.
        $allowedTypes = array(
            Mage_Catalog_Model_Product_Type::TYPE_SIMPLE,
            Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE,
            Mage_Catalog_Model_Product_Type::TYPE_GROUPED,
            Mage_Catalog_Model_Product_Type::TYPE_BUNDLE,
        );
        if (!in_array($product->getTypeId(), $allowedTypes, true)) {
            return null;
        }

        // Visible at least in search or catalog.
        $visibility = (int) $product->getVisibility();
        if ($visibility === Mage_Catalog_Model_Product_Visibility::VISIBILITY_NOT_VISIBLE) {
            return null;
        }

        $prices = $this->_getPriceData($product);

        // is_visible: marks whether the product can be shown in a SEARCH. The
        // index is also populated with visibility=2 (catalog-only) products and
        // products without a price; this flag lets searches filter them without
        // an SQL post-filter coupled to Magento. The enabled status is already
        // guaranteed (map() returns null for disabled products, above).
        // It does NOT require "being in a category": a search-visible product
        // with no category must still be findable - categories are for browsing
        // the catalog, not for searching.
        $visibleForSearch = ($visibility === Mage_Catalog_Model_Product_Visibility::VISIBILITY_IN_SEARCH
                          || $visibility === Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH);
        $hasPrice  = ((float) $prices['price'] > 0) || ((float) $prices['price_max'] > 0);
        $inWebsite = count($product->getWebsiteIds()) > 0;
        $isVisible = ($visibleForSearch && $hasPrice && $inWebsite);

        // Mibizum id = SKU. More readable and stable than entity_id across
        // different merchant stores. If the merchant changes the SKU, run a full
        // Resync (the old id is orphaned; a future version will capture the SKU
        // pre-save and send an explicit DELETE).
        $sku = trim((string) $product->getSku());
        if ($sku === '') return null;

        $doc = array(
            // Meilisearch document ids only allow [A-Za-z0-9_-]; a raw SKU with an
            // accent (Ñ, á, ç…) is rejected by the engine AND fails the entire
            // batch it travels in, silently dropping the ~50 OTHER products that
            // shared that batch. We sanitize the id; the original SKU is kept in
            // the `sku` field for display and click attribution.
            'id'           => self::sanitizeDocId($sku),
            // Document type discriminator - lets the tenant index mix products
            // with other types (ingredients, posts, etc.) in the future.
            'doc_type'     => 'product',
            'sku'          => $sku,
            'product_id'   => (int) $product->getId(),
            'name'         => (string) $product->getName(),
            // Descriptions: the product's editorial text, without HTML. They are
            // STORED in the document, but the engine does NOT search in them -
            // they are not in searchableAttributes (see
            // Config_Schema::getSyntheticSearchableFields, note). Indexing them
            // created too much noise. They are kept in the doc in case
            // description search is re-enabled without having to reindex.
            'short_description' => $this->_cleanText($product->getShortDescription()),
            'description'       => $this->_cleanText($product->getDescription()),
            'url'          => $this->_getProductUrl($product),
            'image_url'    => $this->_getImageUrl($product),
            'price'        => $prices['price'],
            'price_orig'   => $prices['price_orig'],
            'price_min'    => $prices['price_min'],
            'price_max'    => $prices['price_max'],
            'in_offer'     => $this->_isInOffer($product),
            'in_stock'     => $this->_isInStock($product),
            'stock_qty'    => $this->_getStockQty($product),
            'featured'     => (bool) $product->getData('featured'),
            'visibility'   => $visibility,
            'is_visible'   => $isVisible,
            'type_id'      => (string) $product->getTypeId(),
            'created_at'   => $this->_isoDate($product->getCreatedAt()),
            'created_at_ts' => $this->_timestamp($product->getCreatedAt()),
            'categories'   => $this->_getCategoryNames($product),
        );

        // Custom attributes configured as searchable/filterable/sortable.
        foreach ($this->_getAttributeConfigs() as $code => $cfg) {
            if (!$cfg['enabled']) {
                continue;
            }
            $value = $product->getData($code);
            if ($value === null || $value === '') {
                continue;
            }

            // If it is a "select"/"multiselect" attribute in Magento, resolve it
            // to readable labels (not numeric IDs).
            $resolved = $this->_resolveAttributeValue($product, $code, $value);
            if ($resolved !== null && $resolved !== '') {
                $doc[$code] = $resolved;
            }
        }

        // Nature badge (e.g. Hydrosol, Essential Oil). At most 1 per product: the
        // one with the lowest sort_priority among those matching one of the
        // product's categories. The matching and descendant-expansion logic lives
        // in Helper/Data.php::getNatureBadgesIndex.
        $natureBadge = $this->_getNatureBadgeForProduct($product);
        if ($natureBadge) {
            $doc['nature_badge'] = $natureBadge;
        }

        // Attribute badges (informational): an array, 0..N per product. Each item
        // already carries its resolved product value as the label.
        $attributeBadges = $this->_getAttributeBadgesForProduct($product);
        if (!empty($attributeBadges)) {
            $doc['attribute_badges'] = $attributeBadges;
        }

        // System badges (stock_out / stock_low / in_offer / new / featured):
        // resolved per product from its live state (stock, special price, news
        // dates, featured flag) + the merchant's visual overrides, and published
        // in the generic `_badges` array. The SDK already renders that array with
        // full visuals (icon_svg/icon_url, shape, display_mode, position), so we
        // do NOT need any client change — only that the icons be SVG/URL, since
        // FontAwesome classes cannot resolve inside the widget's shadow DOM.
        $systemBadges = $this->_getSystemBadgesForProduct($product);
        if (!empty($systemBadges)) {
            $doc['_badges'] = $systemBadges;
        }

        // Available formats (product children if grouped/configurable). Each
        // format = {label, in_stock, qty}. The frontend shows chips under the
        // name, striking through the out-of-stock ones.
        $formats = $this->_getProductFormats($product);
        if (!empty($formats)) {
            $doc['formats'] = $formats;
        }

        return $doc;
    }

    /**
     * Resolves the product's available formats from its children.
     *
     * Grouped products (a common dominant type) have each child as a simple
     * product with its own name (e.g. "Lavender Essential Oil 10ml") and SKU
     * (e.g. "AES-LAVAN010"). The format is extracted with a regex:
     *
     *   1. From the name: /(\d+(?:[.,]\d+)?)\s*(ml|l|kg|g|gr)\b/i
     *   2. Fallback from the SKU: /(\d{2,4})$/ (assumes ml)
     *
     * Each child is sorted ascending by the numeric value of the format. Simple
     * / bundle products return no formats (return []).
     *
     * @param Mage_Catalog_Model_Product $product
     * @return array  [{label, in_stock, qty}] or []
     */
    protected function _getProductFormats($product)
    {
        $type = $product->getTypeId();
        if (!in_array($type, array(
            Mage_Catalog_Model_Product_Type::TYPE_GROUPED,
            Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE,
        ), true)) {
            return array();
        }

        $children = array();
        try {
            $typeInst = $product->getTypeInstance(true);
            if ($type === Mage_Catalog_Model_Product_Type::TYPE_GROUPED) {
                $children = $typeInst->getAssociatedProducts($product);
            } else {
                $children = $typeInst->getUsedProducts(null, $product);
            }
        } catch (Exception $e) {
            return array();
        }
        if (empty($children)) {
            return array();
        }

        $formats = array();
        foreach ($children as $c) {
            $parsed = $this->_extractFormatLabel($c);
            if (!$parsed) {
                continue;
            }
            $qty = 0;
            try {
                $stock = Mage::getModel('cataloginventory/stock_item')->loadByProduct($c);
                $qty = (int) $stock->getQty();
            } catch (Exception $e) {}
            $formats[] = array(
                'label'      => $parsed['label'],
                'sort_value' => $parsed['sort_value'],
                'in_stock'   => $qty > 0,
                'qty'        => $qty,
            );
        }

        usort($formats, function ($a, $b) {
            return $a['sort_value'] - $b['sort_value'];
        });

        $out = array();
        foreach ($formats as $f) {
            $out[] = array(
                'label'    => $f['label'],
                'in_stock' => $f['in_stock'],
                'qty'      => $f['qty'],
            );
        }
        return $out;
    }

    protected function _extractFormatLabel($child)
    {
        $name = (string) $child->getName();
        $sku  = (string) $child->getSku();

        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(ml|l|kg|g|gr)\b/iu', $name, $m)) {
            $num  = (float) str_replace(',', '.', $m[1]);
            $unit = strtolower($m[2]);
            $normalized = $num;
            if ($unit === 'l')  $normalized = $num * 1000;
            if ($unit === 'kg') $normalized = $num * 1000;
            if ($unit === 'gr') $unit = 'g';
            return array(
                'label'      => $this->_formatNumber($num) . $unit,
                'sort_value' => $normalized,
            );
        }
        if (preg_match('/(\d{2,4})$/', $sku, $m)) {
            $num = (int) $m[1];
            if ($num > 0 && $num < 100000) {
                return array(
                    'label'      => $num . 'ml',
                    'sort_value' => $num,
                );
            }
        }
        return null;
    }

    protected function _formatNumber($num)
    {
        if (floor($num) == $num) return (string) (int) $num;
        return rtrim(rtrim(number_format($num, 2, '.', ''), '0'), '.');
    }

    /**
     * Resolves the active attribute badges against the product. For each
     * configured badge it reads the product's real attribute value (resolving
     * option labels for selects/multiselects) and returns a self-contained blob
     * with the value + the badge's visual config.
     *
     * @param Mage_Catalog_Model_Product $product
     * @return array[]
     */
    protected function _getAttributeBadgesForProduct($product)
    {
        $list = Mage::helper('mibizum_sync')->getAttributeBadgesList();
        if (empty($list)) {
            return array();
        }

        // Product categories (ids). Local cache so we do not call
        // getCategoryIds() N times (one per badge).
        $productCatIds = null;

        $out = array();
        foreach ($list as $cfg) {
            // Category filter: if the badge has category_filter populated and the
            // product is NOT in one of those categories, skip silently. No filter
            // (null) => the badge applies to all categories (compat).
            if (!empty($cfg['category_filter'])) {
                if ($productCatIds === null) {
                    $productCatIds = array_map('intval', (array) $product->getCategoryIds());
                }
                $allowed = array_map('intval', $cfg['category_filter']);
                $intersect = array_intersect($productCatIds, $allowed);
                if (empty($intersect)) {
                    continue;
                }
            }
            $code = $cfg['attribute_code'];
            try {
                $rawValue = $product->getData($code);
                if ($rawValue === null || $rawValue === '' || $rawValue === false) {
                    // The product has no value for this attribute. We use
                    // label_fallback only if the admin set it explicitly.
                    if ($cfg['label_fallback']) {
                        $label = $cfg['label_fallback'];
                    } else {
                        continue; // no value + no fallback => no badge
                    }
                } else {
                    // Resolve a readable label. getAttributeText() resolves
                    // select/multiselect options (id 47 -> "China"). For
                    // text/textarea/number it returns the raw value.
                    $label = null;
                    try {
                        $textVal = $product->getAttributeText($code);
                        if (is_array($textVal)) {
                            $label = implode(', ', $textVal);
                        } elseif ($textVal !== false && $textVal !== null && $textVal !== '') {
                            $label = (string) $textVal;
                        }
                    } catch (Exception $e) {}
                    if ($label === null || $label === '') {
                        $label = (string) $rawValue;
                    }
                }
                if ($label === '' || $label === null) {
                    continue;
                }
                $out[] = array(
                    'attribute_code'  => $code,
                    'label'           => $label,
                    'color_hex'       => $cfg['color_hex'],
                    'text_color_hex'  => $cfg['text_color_hex'],
                    'icon_svg'        => $cfg['icon_svg'],
                    'icon_url'        => $cfg['icon_url'],
                    'icon_fa_class'   => isset($cfg['icon_fa_class']) ? $cfg['icon_fa_class'] : null,
                    'position'        => $cfg['position'],
                    'shape'           => $cfg['shape'],
                    'display_mode'    => $cfg['display_mode'],
                    'sort_priority'   => $cfg['sort_priority'],
                );
            } catch (Exception $e) {
                // Invalid attribute or not loaded on the product. Skip silently.
                continue;
            }
        }
        return $out;
    }

    /**
     * Resolve the nature badge for a product from its list of category_ids
     * against the helper's precomputed index.
     *
     * @param Mage_Catalog_Model_Product $product
     * @return array|null
     */
    protected function _getNatureBadgeForProduct($product)
    {
        $catIds = $product->getCategoryIds();
        if (empty($catIds)) {
            return null;
        }
        $index = Mage::helper('mibizum_sync')->getNatureBadgesIndex();
        if (empty($index)) {
            return null;
        }
        $best = null;
        foreach ($catIds as $cid) {
            $cid = (int) $cid;
            if (isset($index[$cid])) {
                if ($best === null || $index[$cid]['sort_priority'] < $best['sort_priority']) {
                    $best = $index[$cid];
                }
            }
        }
        return $best;
    }

    /**
     * Resolves the system badges (stock_out / stock_low / in_offer / new /
     * featured) that apply to a product. Combines the BEHAVIORAL config
     * (enabled/label/threshold/days, getBadgesConfig) with the VISUAL overrides
     * (color/shape/icon/position, getSystemBadgesVisualByKind) and the product's
     * live state. Returns badge blobs in the same self-contained shape the SDK
     * consumes for nature/attribute badges, sorted by sort_priority ASC (the
     * lowest wins its corner, since the SDK de-duplicates by position).
     *
     * stock_out and stock_low are mutually exclusive (a product is either out of
     * stock or low, never both); in_offer/new/featured can stack.
     *
     * @return array  list of badge blobs, or [] when none apply / master off.
     */
    protected function _getSystemBadgesForProduct($product)
    {
        $helper = Mage::helper('mibizum_sync');
        $cfg    = $helper->getBadgesConfig();              // gated by the master toggle
        $visual = $helper->getSystemBadgesVisualByKind();
        if (empty($visual)) {
            return array();
        }

        $inStock = $this->_isInStock($product);
        $qty     = $this->_getStockQty($product);

        // kind => label, for the kinds that apply to THIS product.
        $applies = array();

        if (!$inStock || $qty <= 0) {
            if (!empty($cfg['out_of_stock']['enabled'])) {
                $applies['stock_out'] = $cfg['out_of_stock']['label'];
            }
        } else {
            $threshold = isset($cfg['low_stock']['threshold']) ? (float) $cfg['low_stock']['threshold'] : 0.0;
            if (!empty($cfg['low_stock']['enabled']) && $threshold > 0 && $qty <= $threshold) {
                $applies['stock_low'] = $cfg['low_stock']['label'];
            }
        }

        if (!empty($cfg['in_offer']['enabled']) && $this->_isInOffer($product)) {
            $applies['in_offer'] = $cfg['in_offer']['label'];
        }

        if (!empty($cfg['new']['enabled']) && $this->_isNew($product, (int) $cfg['new']['days'])) {
            $applies['new'] = $cfg['new']['label'];
        }

        if (!empty($cfg['featured']['enabled']) && (bool) $product->getData('featured')) {
            $applies['featured'] = $cfg['featured']['label'];
        }

        $badges = array();
        foreach ($applies as $kind => $label) {
            if (!isset($visual[$kind])) {
                continue;
            }
            $v = $visual[$kind];
            $badges[] = array(
                'label'          => (string) $label,
                'color_hex'      => $v['color_hex'],
                'text_color_hex' => $v['text_color_hex'],
                'icon_svg'       => $v['icon_svg'],
                'icon_url'       => $v['icon_url'],
                'icon_fa_class'  => $v['icon_fa_class'],
                'display_mode'   => $v['display_mode'],
                'position'       => $v['position'],
                'shape'          => $v['shape'],
                'sort_priority'  => $v['sort_priority'],
            );
        }

        // Lowest sort_priority first: it wins its corner when the SDK collapses
        // two badges to the same position.
        usort($badges, function ($a, $b) {
            return $a['sort_priority'] - $b['sort_priority'];
        });

        return $badges;
    }

    /**
     * Whether a product counts as "new". Prefers the explicit Magento news
     * window (news_from_date / news_to_date) when set; otherwise falls back to
     * "created within the last $days days".
     */
    protected function _isNew($product, $days)
    {
        $now = time();

        $from = $product->getNewsFromDate();
        if ($from) {
            $fromTs = strtotime($from);
            $to     = $product->getNewsToDate();
            $toTs   = $to ? strtotime($to) : null;
            if ($fromTs && $fromTs <= $now && ($toTs === null || $toTs >= $now)) {
                return true;
            }
        }

        if ($days > 0) {
            $created = $product->getCreatedAt();
            if ($created) {
                $createdTs = strtotime($created);
                if ($createdTs && $createdTs >= ($now - $days * 86400)) {
                    return true;
                }
            }
        }

        return false;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    protected function _getProductUrl($product)
    {
        try {
            // The indexer typically runs in admin context (cron/observer):
            // getProductUrl() returns the native /catalog/product/view/id/N/s/...
            // URL because request_path is not hydrated without a store frontend.
            // We load the url_rewrite directly by id_path = "product/N" to get the
            // canonical request_path (without a category) - robust regardless of
            // context. Without this, Google indexes duplicate content.
            //
            // Multi-store: the worker switches the current store to the store-view
            // being indexed, so we use IT (each store-view has its own URLs). We
            // fall back to the default store-view only when there is no current
            // store context (e.g. the mapper is called standalone).
            $current = Mage::app()->getStore();
            if ($current && $current->getId()) {
                $storeId = (int) $current->getId();
            } else {
                $storeId = Mage::app()->getDefaultStoreView()
                    ? Mage::app()->getDefaultStoreView()->getId()
                    : 1;
            }

            $rewrite = Mage::getModel('core/url_rewrite')
                ->setStoreId($storeId)
                ->loadByIdPath('product/' . $product->getId());

            if ($rewrite->getId() && $rewrite->getRequestPath()) {
                $base = Mage::app()->getStore($storeId)
                    ->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK);
                return rtrim($base, '/') . '/' . ltrim($rewrite->getRequestPath(), '/');
            }

            // Fallback: native Magento URL if there is no rewrite (a freshly
            // created product without a URL reindex yet).
            return $product->getProductUrl(false);
        } catch (Exception $e) {
            return '';
        }
    }

    protected function _getImageUrl($product)
    {
        $img = $product->getImage();
        if (!$img || $img === 'no_selection') {
            return '';
        }
        // We use the ORIGINAL image URL instead of going through resize().
        // Reason: on a mass reindex (1000+ products), resize() loads GD for each
        // image and eats memory without freeing it between products -> OOM. The
        // JS client scales visually with CSS (object-fit, max-width). If
        // CDN-friendly thumbnails are needed later, they are generated in a
        // separate build.
        try {
            $mediaUrl = Mage::app()->getStore()
                ->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA);
            return rtrim($mediaUrl, '/') . '/catalog/product' . $img;
        } catch (Exception $e) {
            return '';
        }
    }

    /**
     * Computes the main price + min/max range.
     *
     * - simple: single price (final + orig).
     * - configurable: min/max over the used simple children.
     * - grouped: min/max over the associated products.
     * - bundle: uses Magento's dynamic price model.
     *
     * @return array{price: float, price_orig: float, price_min: float, price_max: float}
     */
    protected function _getPriceData($product)
    {
        $type = $product->getTypeId();
        $price = $this->_round($product->getFinalPrice());
        $priceOrig = $this->_round($product->getPrice());

        if ($type === Mage_Catalog_Model_Product_Type::TYPE_GROUPED) {
            $range = $this->_priceRangeFromAssociated($product);
            return array(
                'price'      => $range['min'],
                'price_orig' => $range['min'],
                'price_min'  => $range['min'],
                'price_max'  => $range['max'],
            );
        }

        if ($type === Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE) {
            $range = $this->_priceRangeFromConfigurableChildren($product);
            return array(
                'price'      => $range['min'],
                'price_orig' => $range['min'],
                'price_min'  => $range['min'],
                'price_max'  => $range['max'],
            );
        }

        if ($type === Mage_Catalog_Model_Product_Type::TYPE_BUNDLE) {
            $range = $this->_priceRangeFromBundle($product);
            return array(
                'price'      => $range['min'],
                'price_orig' => $range['min'],
                'price_min'  => $range['min'],
                'price_max'  => $range['max'],
            );
        }

        // simple: single price.
        return array(
            'price'      => $price,
            'price_orig' => $priceOrig,
            'price_min'  => $price,
            'price_max'  => $price,
        );
    }

    protected function _priceRangeFromAssociated($product)
    {
        try {
            $assoc = $product->getTypeInstance(true)->getAssociatedProducts($product);
            $prices = array();
            foreach ($assoc as $child) {
                $p = (float) $child->getFinalPrice();
                if ($p > 0) {
                    $prices[] = $p;
                }
            }
            if (empty($prices)) {
                return array('min' => 0.0, 'max' => 0.0);
            }
            return array('min' => $this->_round(min($prices)), 'max' => $this->_round(max($prices)));
        } catch (Exception $e) {
            return array('min' => 0.0, 'max' => 0.0);
        }
    }

    protected function _priceRangeFromConfigurableChildren($product)
    {
        try {
            $children = $product->getTypeInstance(true)->getUsedProducts(null, $product);
            $prices = array();
            foreach ($children as $child) {
                $p = (float) $child->getFinalPrice();
                if ($p > 0) {
                    $prices[] = $p;
                }
            }
            if (empty($prices)) {
                return array('min' => $this->_round($product->getFinalPrice()), 'max' => $this->_round($product->getFinalPrice()));
            }
            return array('min' => $this->_round(min($prices)), 'max' => $this->_round(max($prices)));
        } catch (Exception $e) {
            return array('min' => 0.0, 'max' => 0.0);
        }
    }

    protected function _priceRangeFromBundle($product)
    {
        try {
            $priceModel = $product->getPriceModel();
            list($min, $max) = $priceModel->getTotalPrices($product, null, null, false);
            return array('min' => $this->_round($min), 'max' => $this->_round($max));
        } catch (Exception $e) {
            return array('min' => 0.0, 'max' => 0.0);
        }
    }

    protected function _isInStock($product)
    {
        $stockItem = $product->getStockItem();
        if (!$stockItem) {
            $stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product);
        }
        return $stockItem ? (bool) $stockItem->getIsInStock() : false;
    }

    protected function _getStockQty($product)
    {
        $stockItem = $product->getStockItem();
        if (!$stockItem) {
            $stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product);
        }
        return $stockItem ? (float) $stockItem->getQty() : 0.0;
    }

    protected function _isInOffer($product)
    {
        $special = $product->getSpecialPrice();
        $price   = $product->getPrice();
        if (!$special || !$price) {
            return false;
        }
        if ((float) $special >= (float) $price) {
            return false;
        }

        // Check the dates if they are set.
        $from = $product->getSpecialFromDate();
        $to   = $product->getSpecialToDate();
        $now  = time();

        if ($from && strtotime($from) > $now) {
            return false;
        }
        if ($to && strtotime($to) < $now) {
            return false;
        }
        return true;
    }

    /**
     * Sanitizes a SKU into a valid Meilisearch document id.
     *
     * Meilisearch only accepts ids matching `^[A-Za-z0-9_-]{1,511}$`. A SKU with
     * an accent ("AES-LAVESPAÑA") or any other character is rejected with
     * `invalid_document_id`, AND — crucially — that failure aborts the WHOLE
     * batch it travels in, so ~50 other valid products are silently dropped from
     * the index on every reindex. We transliterate common Latin accents to ASCII
     * and replace any remaining invalid character with '-'. The ORIGINAL SKU is
     * preserved in the document's `sku` field for display/click attribution; only
     * the engine primary key is normalized.
     *
     * Deterministic and idempotent: a SKU already in the allowed charset is
     * returned unchanged, so re-indexing does not orphan existing documents.
     *
     * @param  string $sku
     * @return string  a non-empty id within Meili's allowed charset
     */
    public static function sanitizeDocId($sku)
    {
        $map = array(
            'á'=>'a','à'=>'a','ä'=>'a','â'=>'a','ã'=>'a','å'=>'a',
            'Á'=>'A','À'=>'A','Ä'=>'A','Â'=>'A','Ã'=>'A','Å'=>'A',
            'é'=>'e','è'=>'e','ë'=>'e','ê'=>'e','É'=>'E','È'=>'E','Ë'=>'E','Ê'=>'E',
            'í'=>'i','ì'=>'i','ï'=>'i','î'=>'i','Í'=>'I','Ì'=>'I','Ï'=>'I','Î'=>'I',
            'ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o','õ'=>'o','Ó'=>'O','Ò'=>'O','Ö'=>'O','Ô'=>'O','Õ'=>'O',
            'ú'=>'u','ù'=>'u','ü'=>'u','û'=>'u','Ú'=>'U','Ù'=>'U','Ü'=>'U','Û'=>'U',
            'ñ'=>'n','Ñ'=>'N','ç'=>'c','Ç'=>'C','ý'=>'y','ÿ'=>'y','Ý'=>'Y',
        );
        $s = strtr((string) $sku, $map);
        $s = preg_replace('/[^A-Za-z0-9_-]/', '-', $s);
        $s = preg_replace('/-+/', '-', $s);
        $s = trim($s, '-');
        if ($s === '') {
            $s = 'p';
        }
        return substr($s, 0, 511);
    }

    protected function _round($value, $decimals = 2)
    {
        return (float) number_format((float) $value, $decimals, '.', '');
    }

    /**
     * Normalizes a rich-text field (Magento's short/long description, which
     * arrives with HTML, entities and widgets) into plain text suitable for
     * indexing and searching.
     *
     *  - Removes entire script/style blocks (their content is not searchable).
     *  - Turns each tag into a space (so words from adjacent blocks do not stick
     *    together: "<p>oil</p><p>rose</p>" -> "oil rose", not "oilrose").
     *  - Decodes HTML entities (&amp; &nbsp; &aacute; ...).
     *  - Collapses the resulting whitespace.
     *
     * @param string|null $html
     * @return string  Plain text (empty string if there is no content).
     */
    protected function _cleanText($html)
    {
        if ($html === null || $html === '' || $html === false) {
            return '';
        }
        $text = (string) $html;
        // Blocks with no searchable text.
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $text);
        // Each tag -> a space.
        $text = preg_replace('/<[^>]+>/', ' ', $text);
        // HTML entities -> characters.
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        // Collapse whitespace (includes the already-decoded &nbsp;).
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim((string) $text);
    }

    protected function _isoDate($magentoDate)
    {
        if (!$magentoDate) {
            return null;
        }
        $ts = strtotime($magentoDate);
        return $ts ? date('c', $ts) : null;
    }

    protected function _timestamp($magentoDate)
    {
        if (!$magentoDate) {
            return 0;
        }
        $ts = strtotime($magentoDate);
        return $ts ? (int) $ts : 0;
    }

    protected function _getCategoryNames($product)
    {
        $names = array();
        $catIds = $product->getCategoryIds();
        if (empty($catIds)) {
            return $names;
        }
        foreach ($catIds as $catId) {
            try {
                $cat = Mage::getModel('catalog/category')->load((int) $catId);
                if ($cat && $cat->getId() && $cat->getName()) {
                    $names[] = (string) $cat->getName();
                }
            } catch (Exception $e) {
                // skip a broken category
            }
        }
        return array_values(array_unique($names));
    }

    /**
     * For dropdown/multiselect attributes, resolve the numeric value to the
     * readable label(s).
     */
    protected function _resolveAttributeValue($product, $code, $value)
    {
        try {
            $attr = $product->getResource()->getAttribute($code);
            if (!$attr) {
                return $value;
            }
            $frontendInput = $attr->getFrontendInput();

            if ($frontendInput === 'select') {
                $label = $attr->getSource()->getOptionText($value);
                return is_string($label) ? $label : $value;
            }

            if ($frontendInput === 'multiselect') {
                $ids = is_array($value) ? $value : explode(',', (string) $value);
                $labels = array();
                foreach ($ids as $id) {
                    $lbl = $attr->getSource()->getOptionText($id);
                    if (is_string($lbl) && $lbl !== '') {
                        $labels[] = $lbl;
                    }
                }
                return $labels;
            }

            // text/textarea/decimal/int -> raw value
            if (is_numeric($value) && in_array($attr->getBackendType(), array('int', 'decimal'), true)) {
                return $attr->getBackendType() === 'int' ? (int) $value : (float) $value;
            }
            return is_string($value) ? trim($value) : $value;

        } catch (Exception $e) {
            return $value;
        }
    }

    /**
     * Load and cache the attribute config.
     *
     * @return array {attribute_code => {is_searchable, is_filterable, ...}}
     */
    protected function _getAttributeConfigs()
    {
        if (self::$_attrConfigCache !== null) {
            return self::$_attrConfigCache;
        }

        $db = Mage::getSingleton('core/resource')->getConnection('core_read');
        $table = Mage::getSingleton('core/resource')->getTableName('mibizum_sync_attribute_config');

        $rows = $db->fetchAll("SELECT * FROM $table WHERE enabled = 1 ORDER BY display_order");
        $cache = array();
        foreach ($rows as $r) {
            $cache[$r['attribute_code']] = array(
                'enabled'          => (bool) $r['enabled'],
                'is_searchable'    => (bool) $r['is_searchable'],
                'is_filterable'    => (bool) $r['is_filterable'],
                'is_sortable'      => (bool) $r['is_sortable'],
                'searchable_boost' => (float) $r['searchable_boost'],
                'facet_type'       => $r['facet_type'],
            );
        }
        self::$_attrConfigCache = $cache;
        return $cache;
    }

    /** Reset the cache. Useful after admin changes. */
    public static function resetCache()
    {
        self::$_attrConfigCache = null;
    }
}
