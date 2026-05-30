<?php
/**
 * Mibizum_Sync upgrade 0.2.0 -> 0.3.0
 *
 * Configurable badges: extends mibizum_sync_nature_badges with presentation
 * fields (display_mode, position, shape) and creates the new
 * mibizum_sync_badge_palette table for predefined colors selectable from the admin.
 *
 * Changes:
 *   - mibizum_sync_nature_badges += display_mode (icon_only|text_only|icon_and_text)
 *                       += position (top_left|top_right|bottom_left|bottom_right|below_image)
 *                       += shape (pill|circle|square_rounded)
 *   - mibizum_sync_badge_palette: a color palette with label + hex, selectable
 *     from a dropdown in the badge form. The admin can add new ones.
 *
 * Conservative defaults: current behavior = icon_and_text + bottom_left + pill.
 *
 * Compatible with PHP 5.4+.
 *
 * @var Mage_Core_Model_Resource_Setup $installer
 */

$installer = $this;
$installer->startSetup();

$conn = $installer->getConnection();

// ---------------------------------------------------------------------------
// Extend mibizum_sync_nature_badges with presentation columns
// ---------------------------------------------------------------------------
$naturesTable = $installer->getTable('mibizum_sync/nature');

if (!$conn->tableColumnExists($naturesTable, 'display_mode')) {
    $conn->addColumn($naturesTable, 'display_mode', array(
        'type'     => Varien_Db_Ddl_Table::TYPE_TEXT,
        'length'   => 16,
        'nullable' => false,
        'default'  => 'icon_and_text',
        'after'    => 'color_hex',
        'comment'  => 'icon_only | text_only | icon_and_text',
    ));
}

if (!$conn->tableColumnExists($naturesTable, 'position')) {
    $conn->addColumn($naturesTable, 'position', array(
        'type'     => Varien_Db_Ddl_Table::TYPE_TEXT,
        'length'   => 20,
        'nullable' => false,
        'default'  => 'bottom_left',
        'after'    => 'display_mode',
        'comment'  => 'top_left | top_right | bottom_left | bottom_right | below_image',
    ));
}

if (!$conn->tableColumnExists($naturesTable, 'shape')) {
    $conn->addColumn($naturesTable, 'shape', array(
        'type'     => Varien_Db_Ddl_Table::TYPE_TEXT,
        'length'   => 16,
        'nullable' => false,
        'default'  => 'pill',
        'after'    => 'position',
        'comment'  => 'pill | circle | square_rounded',
    ));
}

// `kind` discriminates the badge evaluation logic:
//   - 'nature': matches via mibizum_sync_nature_badge_categories (the current one).
//   - 'stock_out': product with stock_qty=0.
//   - 'stock_low': product with 0 < stock_qty <= trigger_config.threshold.
//   - 'in_offer': product with price_orig > price.
//   - 'new':      product with created_at within trigger_config.days.
//   - 'featured': product with the featured=true flag.
// The kinds other than 'nature' are "system badges" - they do not use category
// assignments, their trigger is automatic. They CANNOT be deleted (only
// disabled) because deleting them loses the configuration.
if (!$conn->tableColumnExists($naturesTable, 'kind')) {
    $conn->addColumn($naturesTable, 'kind', array(
        'type'     => Varien_Db_Ddl_Table::TYPE_TEXT,
        'length'   => 24,
        'nullable' => false,
        'default'  => 'nature',
        'after'    => 'enabled',
        'comment'  => 'nature | stock_out | stock_low | in_offer | new | featured',
    ));
    $conn->addIndex(
        $naturesTable,
        $installer->getIdxName($naturesTable, array('kind')),
        array('kind')
    );
}

// `trigger_config` JSON with the kind's parameters:
//   - stock_low: {"threshold": 5}
//   - new:       {"days": 30}
//   - others:    {} or NULL.
if (!$conn->tableColumnExists($naturesTable, 'trigger_config')) {
    $conn->addColumn($naturesTable, 'trigger_config', array(
        'type'     => Varien_Db_Ddl_Table::TYPE_TEXT,
        'length'   => 1024,
        'nullable' => true,
        'after'    => 'kind',
        'comment'  => 'JSON with parameters specific to the kind (threshold, days, etc.)',
    ));
}

// ---------------------------------------------------------------------------
// New table: color palette
// ---------------------------------------------------------------------------
$paletteTable = $installer->getTable('mibizum_sync/badgePalette');

if (!$conn->isTableExists($paletteTable)) {

    $t = $conn->newTable($paletteTable)
        ->addColumn('id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
            'identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true,
        ), 'ID')
        ->addColumn('label', Varien_Db_Ddl_Table::TYPE_TEXT, 64, array(
            'nullable' => false,
        ), 'Human-readable label (e.g. "Brand green", "Warning red")')
        ->addColumn('color_hex', Varien_Db_Ddl_Table::TYPE_TEXT, 7, array(
            'nullable' => false,
        ), 'Hex code "#RRGGBB"')
        ->addColumn('text_color_hex', Varien_Db_Ddl_Table::TYPE_TEXT, 7, array(
            'nullable' => false, 'default' => '#FFFFFF',
        ), 'Text color over this background (default white)')
        ->addColumn('sort_order', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, array(
            'unsigned' => true, 'nullable' => false, 'default' => 100,
        ), 'Order in the admin form dropdown')
        ->addColumn('is_default', Varien_Db_Ddl_Table::TYPE_BOOLEAN, null, array(
            'nullable' => false, 'default' => 0,
        ), 'Color marked as default when creating a new badge')
        ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, array(
            'nullable' => false,
        ), 'Created at')
        ->addIndex(
            $installer->getIdxName($paletteTable, array('color_hex'), Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE),
            array('color_hex'),
            array('type' => Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE)
        )
        ->setComment('Predefined color palette for badges');

    $conn->createTable($t);

    // Initial seed: brand palette + common colors for notification systems.
    $now = Varien_Date::now();
    $seed = array(
        // Base brand palette (logo)
        array('MIBIZUM Green',      '#1E9C3C', '#FFFFFF', 10, 1),
        array('MIBIZUM Magenta',    '#9C3C9C', '#FFFFFF', 20, 0),
        array('Lilac',           '#8B3A87', '#FFFFFF', 30, 0),
        array('MIBIZUM Gray',       '#3C3C3C', '#FFFFFF', 40, 0),

        // Functional colors (notification system)
        array('Warning Red',     '#D63D3D', '#FFFFFF', 50, 0),
        array('Urgent Orange','#F0A030', '#FFFFFF', 60, 0),
        array('Highlight Yellow', '#F4C430', '#3C3C3C', 70, 0),
        array('Info Blue',      '#3C7CB7', '#FFFFFF', 80, 0),
        array('Freshness Cyan',  '#5FB8E8', '#FFFFFF', 90, 0),

        // Extended nature
        array('Light Green',    '#7BC257', '#FFFFFF', 100, 0),
        array('Dark Green',   '#2E7D32', '#FFFFFF', 110, 0),
        array('Earth Brown',  '#8B6914', '#FFFFFF', 120, 0),
        array('Cream',          '#D4A574', '#3C3C3C', 130, 0),
        array('Black',          '#1A1A1A', '#FFFFFF', 140, 0),
        array('White',         '#FFFFFF', '#3C3C3C', 150, 0),
    );

    foreach ($seed as $c) {
        list($label, $hex, $text, $order, $isDefault) = $c;
        $conn->insert($paletteTable, array(
            'label'          => $label,
            'color_hex'      => $hex,
            'text_color_hex' => $text,
            'sort_order'     => $order,
            'is_default'     => $isDefault,
            'created_at'     => $now,
        ));
    }
}

// ---------------------------------------------------------------------------
// Migrate the current system badges (system.xml mibizum_sync/badges/*) into rows
// in mibizum_sync_nature_badges with the corresponding kind. Only if they do not
// already exist.
// ---------------------------------------------------------------------------
$existingKinds = $conn->fetchCol(
    "SELECT kind FROM $naturesTable WHERE kind != 'nature'"
);
$existingKinds = array_map('strtolower', $existingKinds);

$cfg = Mage::getStoreConfig('mibizum_sync/badges');
if (!is_array($cfg)) {
    $cfg = array();
}
$cfgGet = function ($key, $default = '') use ($cfg) {
    return isset($cfg[$key]) && $cfg[$key] !== '' ? $cfg[$key] : $default;
};

$systemBadgesSeed = array(
    array(
        'kind'           => 'stock_out',
        'label'          => $cfgGet('out_of_stock_label', 'Out of stock'),
        'slug'           => 'system-out-of-stock',
        'color_hex'      => '#6B6B75',
        'sort_priority'  => 5,
        'enabled'        => (int) (bool) $cfgGet('out_of_stock_enabled', 1),
        'position'       => 'top_right',
        'trigger_config' => null,
    ),
    array(
        'kind'           => 'stock_low',
        'label'          => $cfgGet('low_stock_label', 'Last units'),
        'slug'           => 'system-last-units',
        'color_hex'      => '#F0A030',
        'sort_priority'  => 10,
        'enabled'        => (int) (bool) $cfgGet('low_stock_enabled', 1),
        'position'       => 'top_left',
        'trigger_config' => json_encode(array(
            'threshold' => (int) $cfgGet('low_stock_threshold', 5),
        )),
    ),
    array(
        'kind'           => 'in_offer',
        'label'          => $cfgGet('in_offer_label', 'On sale'),
        'slug'           => 'system-on-sale',
        'color_hex'      => '#D63D3D',
        'sort_priority'  => 15,
        'enabled'        => (int) (bool) $cfgGet('in_offer_enabled', 1),
        'position'       => 'top_left',
        'trigger_config' => null,
    ),
    array(
        'kind'           => 'new',
        'label'          => $cfgGet('new_label', 'New'),
        'slug'           => 'system-new',
        'color_hex'      => '#8B3A87',
        'sort_priority'  => 20,
        'enabled'        => (int) (bool) $cfgGet('new_enabled', 0),
        'shape'          => 'circle',
        'display_mode'   => 'icon_only',
        'position'       => 'top_right',
        'trigger_config' => json_encode(array(
            'days' => (int) $cfgGet('new_days', 30),
        )),
    ),
    array(
        'kind'           => 'featured',
        'label'          => $cfgGet('featured_label', 'Featured'),
        'slug'           => 'system-featured',
        'color_hex'      => '#1E9C3C',
        'sort_priority'  => 25,
        'enabled'        => (int) (bool) $cfgGet('featured_enabled', 0),
        'shape'          => 'circle',
        'display_mode'   => 'icon_only',
        'position'       => 'top_right',
        'trigger_config' => null,
    ),
);

$now = Varien_Date::now();
foreach ($systemBadgesSeed as $b) {
    if (in_array($b['kind'], $existingKinds, true)) {
        continue;  // already migrated
    }
    $insert = array(
        'kind'           => $b['kind'],
        'label'          => $b['label'],
        'slug'           => $b['slug'],
        'color_hex'      => $b['color_hex'],
        'sort_priority'  => $b['sort_priority'],
        'enabled'        => $b['enabled'],
        'position'       => isset($b['position']) ? $b['position'] : 'top_left',
        'shape'          => isset($b['shape']) ? $b['shape'] : 'pill',
        'display_mode'   => isset($b['display_mode']) ? $b['display_mode'] : 'icon_and_text',
        'trigger_config' => isset($b['trigger_config']) ? $b['trigger_config'] : null,
        'created_at'     => $now,
        'updated_at'     => $now,
    );
    $conn->insert($naturesTable, $insert);
}

$installer->endSetup();
