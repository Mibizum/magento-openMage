<?php
/**
 * Mibizum_Sync 0.6.8 -> 0.6.9
 *
 * System badges now reach the search widget. The ProductMapper resolves the
 * applicable system badges (stock_out / stock_low / in_offer / new / featured)
 * per product from its live state + the visual overrides and publishes them in
 * the document's generic `_badges` array, which the SDK already renders with
 * full visuals (icon, shape, display_mode, position). No client change.
 *
 * IMPORTANT — icons must be SVG, not FontAwesome: the widget renders inside a
 * shadow root that isolates CSS, so a FontAwesome class (`fa-...`) never
 * resolves to a glyph there. This upgrade BACKFILLS inline-SVG equivalents for
 * the three default-iconed system badges (stock_out = empty battery, stock_low =
 * double chevron down, featured = gift), but ONLY when the merchant has not set
 * an icon_svg yet (icon_svg NULL/empty). Rows the merchant already customized
 * are left untouched. The legacy icon_fa_class column is kept as-is.
 *
 * Idempotent: re-running is a no-op once icon_svg is populated.
 *
 * Compatible with PHP 5.4+.
 *
 * @var Mage_Core_Model_Resource_Setup $installer
 */

/** @var Mage_Core_Model_Resource_Setup $installer */
$installer = $this;
$installer->startSetup();

$conn  = $installer->getConnection();
$table = $installer->getTable('mibizum_sync/systemOverride');

// kind => inline SVG (currentColor follows the badge text color).
$icons = array(
    'stock_out' => '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="none"><rect x="2" y="8" width="16" height="8" rx="1.5" stroke="currentColor" stroke-width="1.6"/><rect x="20" y="10.5" width="2.2" height="3" rx="0.6" fill="currentColor"/></svg>',
    'stock_low' => '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="none"><polyline points="6 7 12 13 18 7" fill="none" stroke="currentColor" stroke-width="1.9"/><polyline points="6 13 12 19 18 13" fill="none" stroke="currentColor" stroke-width="1.9"/></svg>',
    'featured'  => '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="none"><rect x="4" y="10" width="16" height="10.5" rx="1" stroke="currentColor" stroke-width="1.5"/><rect x="3" y="7" width="18" height="3.5" rx="0.75" stroke="currentColor" stroke-width="1.4"/><line x1="12" y1="7" x2="12" y2="20.5" stroke="currentColor" stroke-width="1.4"/><path d="M12 7C12 7 9.6 3 7.2 4.3C5.4 5.3 8.2 7 12 7Z" fill="none" stroke="currentColor" stroke-width="1.3"/><path d="M12 7C12 7 14.4 3 16.8 4.3C18.6 5.3 15.8 7 12 7Z" fill="none" stroke="currentColor" stroke-width="1.3"/></svg>',
);

try {
    if ($conn->isTableExists($table)) {
        foreach ($icons as $kind => $svg) {
            $conn->update(
                $table,
                array('icon_svg' => $svg),
                $conn->quoteInto('kind = ?', $kind) . " AND (icon_svg IS NULL OR icon_svg = '')"
            );
        }
    }
} catch (Exception $e) {
    // Non-fatal: a missing table or a write error must not break the upgrade.
    Mage::log('mibizum_sync 0.6.9 icon backfill skipped: ' . $e->getMessage(), Zend_Log::WARN);
}

$installer->endSetup();
