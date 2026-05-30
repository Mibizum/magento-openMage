<?php
/**
 * Mibizum_Sync upgrade 0.3.0 -> 0.4.0
 *
 * Architectural refactor - separate the category badges (nature) from the
 * system badges (stock_out, stock_low, in_offer, new, featured):
 *
 *   1. Remove the 5 system rows migrated in 0.3.0 from mibizum_sync_nature_badges.
 *   2. DROP COLUMN kind, trigger_config on mibizum_sync_nature_badges (no longer
 *      needed - the table goes back to being ONLY category badges).
 *   3. DROP TABLE mibizum_sync_badge_palette - the palette is now derived from the
 *      colors that the existing badges ALREADY use (nature + system overrides).
 *   4. CREATE TABLE mibizum_sync_system_badge_overrides - 1 row per system badge
 *      (5 fixed) with ONLY the 4 visual attributes: color_hex, icon, position,
 *      shape. The labels/enabled/threshold/days stay in system.xml under
 *      Search > Badges (where they were before, not duplicated).
 *
 * Rationale: the unified screen with a kind picker mixed contexts that did not
 * apply (threshold only for stock_low, days only for new, etc.) and was
 * confusing. The separation is cleaner.
 *
 * Compatible with PHP 5.4+.
 *
 * @var Mage_Core_Model_Resource_Setup $installer
 */

$installer = $this;
$installer->startSetup();

$conn = $installer->getConnection();

// ---------------------------------------------------------------------------
// 1. Remove migrated system rows (kind != 'nature')
// ---------------------------------------------------------------------------
$naturesTable = $installer->getTable('mibizum_sync/nature');

if ($conn->tableColumnExists($naturesTable, 'kind')) {
    $deleted = $conn->delete($naturesTable, "kind != 'nature' AND kind IS NOT NULL");
    if ($deleted > 0) {
        Mage::log(
            'mibizum_sync 0.4.0 upgrade: ' . $deleted . ' system rows removed from mibizum_sync_nature_badges',
            Zend_Log::INFO,
            'mibizum_sync.log'
        );
    }
}

// ---------------------------------------------------------------------------
// 2. DROP COLUMN kind, trigger_config
// ---------------------------------------------------------------------------
if ($conn->tableColumnExists($naturesTable, 'kind')) {
    // Drop the index if it exists.
    try {
        $conn->dropIndex($naturesTable, $installer->getIdxName($naturesTable, array('kind')));
    } catch (Exception $e) {}
    $conn->dropColumn($naturesTable, 'kind');
}
if ($conn->tableColumnExists($naturesTable, 'trigger_config')) {
    $conn->dropColumn($naturesTable, 'trigger_config');
}

// ---------------------------------------------------------------------------
// 3. DROP TABLE mibizum_sync_badge_palette
// ---------------------------------------------------------------------------
// IMPORTANT: the alias 'mibizum_sync/badgePalette' is NO longer declared in
// config.xml 0.4.0 (we removed it as part of the refactor). Use the literal
// physical name because getTable() throws "Can't retrieve entity config" if
// the alias does not exist.
$paletteTable = $installer->getTable('mibizum_sync_badge_palette');
if ($conn->isTableExists($paletteTable)) {
    $conn->dropTable($paletteTable);
}

// ---------------------------------------------------------------------------
// 4. CREATE TABLE mibizum_sync_system_badge_overrides
// ---------------------------------------------------------------------------
$overridesTable = $installer->getTable('mibizum_sync/systemOverride');

if (!$conn->isTableExists($overridesTable)) {

    $t = $conn->newTable($overridesTable)
        ->addColumn('id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
            'identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true,
        ), 'ID')
        ->addColumn('kind', Varien_Db_Ddl_Table::TYPE_TEXT, 24, array(
            'nullable' => false,
        ), 'stock_out | stock_low | in_offer | new | featured')
        ->addColumn('color_hex', Varien_Db_Ddl_Table::TYPE_TEXT, 7, array(
            'nullable' => false,
            'default'  => '#1E9C3C',
        ), 'Badge background color')
        ->addColumn('text_color_hex', Varien_Db_Ddl_Table::TYPE_TEXT, 7, array(
            'nullable' => false,
            'default'  => '#FFFFFF',
        ), 'Badge text color')
        ->addColumn('icon_svg', Varien_Db_Ddl_Table::TYPE_TEXT, '4M', array(
            'nullable' => true,
        ), 'Inline SVG (preferred). Recolorable with currentColor.')
        ->addColumn('icon_url', Varien_Db_Ddl_Table::TYPE_TEXT, 255, array(
            'nullable' => true,
        ), 'PNG/JPG fallback URL when there is no SVG')
        ->addColumn('position', Varien_Db_Ddl_Table::TYPE_TEXT, 20, array(
            'nullable' => false,
            'default'  => 'top_left',
        ), 'top_left|top_right|bottom_left|bottom_right|below_image')
        ->addColumn('shape', Varien_Db_Ddl_Table::TYPE_TEXT, 16, array(
            'nullable' => false,
            'default'  => 'pill',
        ), 'pill|circle|square_rounded')
        ->addColumn('display_mode', Varien_Db_Ddl_Table::TYPE_TEXT, 16, array(
            'nullable' => false,
            'default'  => 'icon_and_text',
        ), 'icon_only|text_only|icon_and_text')
        ->addColumn('sort_priority', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, array(
            'unsigned' => true, 'nullable' => false, 'default' => 100,
        ), 'For the same position, the lowest priority wins')
        ->addColumn('updated_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, array(
            'nullable' => false,
        ), 'Updated at')
        ->addIndex(
            $installer->getIdxName($overridesTable, array('kind'), Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE),
            array('kind'),
            array('type' => Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE)
        )
        ->setComment('Visual override of the 5 system badges (logic config stays in system.xml)');

    $conn->createTable($t);

    // Seed: 5 fixed system badges with reasonable defaults.
    $now = Varien_Date::now();
    $seed = array(
        array('stock_out', '#6B6B75', '#FFFFFF', null, null, 'top_right',  'pill',   'icon_and_text', 5),
        array('stock_low', '#F0A030', '#FFFFFF', null, null, 'top_left',   'pill',   'icon_and_text', 10),
        array('in_offer',  '#D63D3D', '#FFFFFF', null, null, 'top_left',   'pill',   'icon_and_text', 15),
        array('new',       '#8B3A87', '#FFFFFF', null, null, 'top_right',  'circle', 'icon_only',     20),
        array('featured',  '#1E9C3C', '#FFFFFF', null, null, 'top_right',  'circle', 'icon_only',     25),
    );

    foreach ($seed as $row) {
        list($kind, $color, $textColor, $svg, $url, $position, $shape, $display, $priority) = $row;
        $conn->insert($overridesTable, array(
            'kind'           => $kind,
            'color_hex'      => $color,
            'text_color_hex' => $textColor,
            'icon_svg'       => $svg,
            'icon_url'       => $url,
            'position'       => $position,
            'shape'          => $shape,
            'display_mode'   => $display,
            'sort_priority'  => $priority,
            'updated_at'     => $now,
        ));
    }
}

$installer->endSetup();
