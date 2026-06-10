<?php
/**
 * Self-heal idempotente de las 3 tablas de badges (#3 forense).
 *
 * Forense en micosmeticacasera vio errores "Base table or view not found" de
 * `mibizum_sync_attribute_badges`, `mibizum_sync_nature_badges` y
 * `mibizum_sync_system_badge_overrides`. Análisis: esas tablas se crean en
 * upgrades tempranos (0.1.0-0.5.0), TODOS antes de 0.6.10, así que el puente
 * directo 0.6.10->0.7.1 no las salta y un store normal las tiene; los errores
 * eran residuo. Aun así, este upgrade GARANTIZA que existan (crea-si-falta) para
 * cualquier store cuyo upgrade temprano se abortara/borrase la tabla con la
 * versión ya avanzada. Idempotente: no toca nada si ya existen.
 *
 * Schemas = estado FINAL acumulado de los upgrades originales (nature incluye las
 * columnas añadidas en 0.2.0-0.3.0 / 0.4.0-0.4.1 / 0.4.2-0.5.0; sin kind/trigger_config
 * que se eliminaron en 0.3.0-0.4.0).
 */
$installer = $this;
$installer->startSetup();
$conn = $installer->getConnection();

// --- mibizum_sync_nature_badges -------------------------------------------
$nature = $installer->getTable('mibizum_sync/nature');
if (!$conn->isTableExists($nature)) {
    $t = $conn->newTable($nature)
        ->addColumn('id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
            'identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true,
        ), 'Badge ID')
        ->addColumn('label', Varien_Db_Ddl_Table::TYPE_TEXT, 64, array('nullable' => false), 'Visible text')
        ->addColumn('slug', Varien_Db_Ddl_Table::TYPE_TEXT, 64, array('nullable' => false), 'URL-safe id')
        ->addColumn('icon_svg', Varien_Db_Ddl_Table::TYPE_TEXT, '4M', array('nullable' => true), 'Inline SVG')
        ->addColumn('icon_url', Varien_Db_Ddl_Table::TYPE_TEXT, 255, array('nullable' => true), 'Icon URL fallback')
        ->addColumn('color_hex', Varien_Db_Ddl_Table::TYPE_TEXT, 7, array('nullable' => false, 'default' => '#1E9C3C'), 'Badge color')
        ->addColumn('display_mode', Varien_Db_Ddl_Table::TYPE_TEXT, 16, array('nullable' => false, 'default' => 'icon_and_text'), 'icon_only|text_only|icon_and_text')
        ->addColumn('position', Varien_Db_Ddl_Table::TYPE_TEXT, 20, array('nullable' => false, 'default' => 'bottom_left'), 'corner')
        ->addColumn('shape', Varien_Db_Ddl_Table::TYPE_TEXT, 16, array('nullable' => false, 'default' => 'pill'), 'pill|circle|square_rounded')
        ->addColumn('text_color_hex', Varien_Db_Ddl_Table::TYPE_TEXT, 7, array('nullable' => true, 'default' => null), 'Text color (auto if null)')
        ->addColumn('icon_fa_class', Varien_Db_Ddl_Table::TYPE_TEXT, 64, array('nullable' => true, 'default' => null), 'Font Awesome class')
        ->addColumn('sort_priority', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, array('unsigned' => true, 'nullable' => false, 'default' => 100), 'Lowest wins')
        ->addColumn('enabled', Varien_Db_Ddl_Table::TYPE_BOOLEAN, null, array('nullable' => false, 'default' => 1), 'Active')
        ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, array('nullable' => false), 'Created at')
        ->addColumn('updated_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, array('nullable' => false), 'Updated at')
        ->addIndex(
            $installer->getIdxName($nature, array('slug'), Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE),
            array('slug'), array('type' => Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE)
        )
        ->addIndex($installer->getIdxName($nature, array('enabled')), array('enabled'))
        ->setComment('Mibizum_Sync nature badges (self-heal 0.7.2)');
    $conn->createTable($t);
} else {
    // tabla existe pero algún upgrade de columna pudo no correr: añadir si faltan.
    if (!$conn->tableColumnExists($nature, 'display_mode')) {
        $conn->addColumn($nature, 'display_mode', array('type' => Varien_Db_Ddl_Table::TYPE_TEXT, 'length' => 16, 'nullable' => false, 'default' => 'icon_and_text', 'comment' => 'display mode'));
    }
    if (!$conn->tableColumnExists($nature, 'position')) {
        $conn->addColumn($nature, 'position', array('type' => Varien_Db_Ddl_Table::TYPE_TEXT, 'length' => 20, 'nullable' => false, 'default' => 'bottom_left', 'comment' => 'corner'));
    }
    if (!$conn->tableColumnExists($nature, 'shape')) {
        $conn->addColumn($nature, 'shape', array('type' => Varien_Db_Ddl_Table::TYPE_TEXT, 'length' => 16, 'nullable' => false, 'default' => 'pill', 'comment' => 'shape'));
    }
    if (!$conn->tableColumnExists($nature, 'text_color_hex')) {
        $conn->addColumn($nature, 'text_color_hex', array('type' => Varien_Db_Ddl_Table::TYPE_TEXT, 'length' => 7, 'nullable' => true, 'default' => null, 'comment' => 'text color'));
    }
    if (!$conn->tableColumnExists($nature, 'icon_fa_class')) {
        $conn->addColumn($nature, 'icon_fa_class', array('type' => Varien_Db_Ddl_Table::TYPE_TEXT, 'length' => 64, 'nullable' => true, 'default' => null, 'comment' => 'FA class'));
    }
}

// --- mibizum_sync_attribute_badges ----------------------------------------
$attr = $installer->getTable('mibizum_sync/attributeBadge');
if (!$conn->isTableExists($attr)) {
    $t = $conn->newTable($attr)
        ->addColumn('id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
            'identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true,
        ), 'ID')
        ->addColumn('attribute_code', Varien_Db_Ddl_Table::TYPE_TEXT, 64, array('nullable' => false), 'attribute_code')
        ->addColumn('label', Varien_Db_Ddl_Table::TYPE_TEXT, 64, array('nullable' => true), 'Fallback label')
        ->addColumn('color_hex', Varien_Db_Ddl_Table::TYPE_TEXT, 7, array('nullable' => false, 'default' => '#1E9C3C'), 'Background')
        ->addColumn('text_color_hex', Varien_Db_Ddl_Table::TYPE_TEXT, 7, array('nullable' => true, 'default' => null), 'Text color')
        ->addColumn('icon_svg', Varien_Db_Ddl_Table::TYPE_TEXT, '4M', array('nullable' => true), 'Inline SVG')
        ->addColumn('icon_url', Varien_Db_Ddl_Table::TYPE_TEXT, 255, array('nullable' => true), 'Icon URL')
        ->addColumn('position', Varien_Db_Ddl_Table::TYPE_TEXT, 20, array('nullable' => false, 'default' => 'top_right'), 'corner')
        ->addColumn('shape', Varien_Db_Ddl_Table::TYPE_TEXT, 16, array('nullable' => false, 'default' => 'pill'), 'shape')
        ->addColumn('display_mode', Varien_Db_Ddl_Table::TYPE_TEXT, 16, array('nullable' => false, 'default' => 'icon_and_text'), 'display mode')
        ->addColumn('sort_priority', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, array('unsigned' => true, 'nullable' => false, 'default' => 100), 'Lowest wins')
        ->addColumn('enabled', Varien_Db_Ddl_Table::TYPE_BOOLEAN, null, array('nullable' => false, 'default' => 1), 'Active')
        ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, array('nullable' => false), 'Created at')
        ->addColumn('updated_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, array('nullable' => false), 'Updated at')
        ->addIndex(
            $installer->getIdxName($attr, array('attribute_code'), Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE),
            array('attribute_code'), array('type' => Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE)
        )
        ->addIndex($installer->getIdxName($attr, array('enabled')), array('enabled'))
        ->setComment('Mibizum_Sync attribute badges (self-heal 0.7.2)');
    $conn->createTable($t);
}

// --- mibizum_sync_system_badge_overrides ----------------------------------
$ov = $installer->getTable('mibizum_sync/systemOverride');
if (!$conn->isTableExists($ov)) {
    $t = $conn->newTable($ov)
        ->addColumn('id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
            'identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true,
        ), 'ID')
        ->addColumn('kind', Varien_Db_Ddl_Table::TYPE_TEXT, 24, array('nullable' => false), 'stock_out|stock_low|in_offer|new|featured')
        ->addColumn('color_hex', Varien_Db_Ddl_Table::TYPE_TEXT, 7, array('nullable' => false, 'default' => '#1E9C3C'), 'Background')
        ->addColumn('text_color_hex', Varien_Db_Ddl_Table::TYPE_TEXT, 7, array('nullable' => false, 'default' => '#FFFFFF'), 'Text color')
        ->addColumn('icon_svg', Varien_Db_Ddl_Table::TYPE_TEXT, '4M', array('nullable' => true), 'Inline SVG')
        ->addColumn('icon_url', Varien_Db_Ddl_Table::TYPE_TEXT, 255, array('nullable' => true), 'Icon URL')
        ->addColumn('position', Varien_Db_Ddl_Table::TYPE_TEXT, 20, array('nullable' => false, 'default' => 'top_left'), 'corner')
        ->addColumn('shape', Varien_Db_Ddl_Table::TYPE_TEXT, 16, array('nullable' => false, 'default' => 'pill'), 'shape')
        ->addColumn('display_mode', Varien_Db_Ddl_Table::TYPE_TEXT, 16, array('nullable' => false, 'default' => 'icon_and_text'), 'display mode')
        ->addColumn('sort_priority', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, array('unsigned' => true, 'nullable' => false, 'default' => 100), 'Lowest wins')
        ->addColumn('updated_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, array('nullable' => false), 'Updated at')
        ->addIndex(
            $installer->getIdxName($ov, array('kind'), Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE),
            array('kind'), array('type' => Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE)
        )
        ->setComment('Mibizum_Sync system badge overrides (self-heal 0.7.2)');
    $conn->createTable($t);
}

$installer->endSetup();
