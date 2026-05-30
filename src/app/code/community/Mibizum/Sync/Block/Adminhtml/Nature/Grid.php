<?php
/**
 * Grid for the badges listing.
 *
 * Compatible with PHP 5.4+.
 */
class Mibizum_Sync_Block_Adminhtml_Nature_Grid extends Mage_Adminhtml_Block_Widget_Grid
{
    public function __construct()
    {
        parent::__construct();
        $this->setId('mibizum_sync_nature_grid');
        $this->setDefaultSort('sort_priority');
        $this->setDefaultDir('ASC');
        $this->setSaveParametersInSession(true);
        $this->setUseAjax(false);
    }

    protected function _prepareCollection()
    {
        $collection = Mage::getModel('mibizum_sync/nature')->getCollection();
        $this->setCollection($collection);
        return parent::_prepareCollection();
    }

    protected function _prepareColumns()
    {
        $h = Mage::helper('mibizum_sync');

        $this->addColumn('id', array(
            'header' => $h->__('ID'),
            'index'  => 'id',
            'width'  => 50,
        ));

        $this->addColumn('icon_preview', array(
            'header'   => $h->__('Icon'),
            'index'    => 'icon_svg',
            'width'    => 60,
            'sortable' => false,
            'filter'   => false,
            'frame_callback' => array($this, 'renderIconPreview'),
        ));

        $this->addColumn('label', array(
            'header' => $h->__('Name'),
            'index'  => 'label',
        ));

        $this->addColumn('slug', array(
            'header' => $h->__('Slug'),
            'index'  => 'slug',
            'width'  => 180,
        ));

        $this->addColumn('color_hex', array(
            'header'   => $h->__('Color'),
            'index'    => 'color_hex',
            'width'    => 100,
            'sortable' => false,
            'frame_callback' => array($this, 'renderColorChip'),
        ));

        $this->addColumn('position', array(
            'header'   => $h->__('Position'),
            'index'    => 'position',
            'width'    => 110,
            'sortable' => false,
        ));

        $this->addColumn('shape', array(
            'header'   => $h->__('Shape'),
            'index'    => 'shape',
            'width'    => 100,
            'sortable' => false,
        ));

        $this->addColumn('display_mode', array(
            'header'   => $h->__('Display'),
            'index'    => 'display_mode',
            'width'    => 110,
            'sortable' => false,
        ));

        $this->addColumn('sort_priority', array(
            'header' => $h->__('Priority'),
            'index'  => 'sort_priority',
            'type'   => 'number',
            'width'  => 80,
        ));

        $this->addColumn('enabled', array(
            'header'  => $h->__('Enabled'),
            'index'   => 'enabled',
            'type'    => 'options',
            'width'   => 70,
            'options' => array(0 => $h->__('No'), 1 => $h->__('Yes')),
        ));

        $this->addColumn('action', array(
            'header'    => $h->__('Actions'),
            'width'     => 80,
            'type'      => 'action',
            'getter'    => 'getId',
            'filter'    => false,
            'sortable'  => false,
            'is_system' => true,
            'actions'   => array(array(
                'caption' => $h->__('Edit'),
                'url'     => array('base' => 'adminhtml/mibizum_sync_nature/edit'),
                'field'   => 'id',
            )),
        ));

        return parent::_prepareColumns();
    }

    protected function _prepareMassaction()
    {
        $h = Mage::helper('mibizum_sync');
        $this->setMassactionIdField('id');
        $this->getMassactionBlock()->setFormFieldName('badge_ids');

        $this->getMassactionBlock()->addItem('enable', array(
            'label' => $h->__('Enable'),
            'url'   => $this->getUrl('adminhtml/mibizum_sync_nature/massEnable'),
        ));
        $this->getMassactionBlock()->addItem('disable', array(
            'label' => $h->__('Disable'),
            'url'   => $this->getUrl('adminhtml/mibizum_sync_nature/massDisable'),
        ));
        $this->getMassactionBlock()->addItem('delete', array(
            'label'   => $h->__('Remove'),
            'url'     => $this->getUrl('adminhtml/mibizum_sync_nature/massDelete'),
            'confirm' => $h->__('Confirm deletion?'),
        ));
        return $this;
    }

    public function getRowUrl($row)
    {
        return $this->getUrl('adminhtml/mibizum_sync_nature/edit', array('id' => $row->getId()));
    }

    /** Inline renderers for custom columns. */

    public function renderIconPreview($value, $row)
    {
        $svg = (string) $row->getIconSvg();
        $url = (string) $row->getIconUrl();
        $faClass = (string) $row->getIconFaClass();
        $color = htmlspecialchars((string) $row->getColorHex(), ENT_QUOTES);

        if ($faClass !== '') {
            // Font Awesome 4.7. Loads the CSS from the frontend if not already
            // present (a single <link> in each cell does not duplicate requests).
            $faCssUrl = htmlspecialchars(
                Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_SKIN)
                    . 'frontend/base/default/css/plumrocket/pramp/font-awesome.min.css',
                ENT_QUOTES
            );
            $faClass = preg_match('/^fa-[a-z0-9-]+$/', $faClass) ? $faClass : '';
            return '<link rel="stylesheet" href="' . $faCssUrl . '" />'
                . '<span style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;color:' . $color . ';">'
                . '<i class="fa ' . $faClass . '" style="font-size:24px;line-height:1;"></i>'
                . '</span>';
        }
        if ($svg !== '') {
            // The raw <svg> stored in the DB carries its own width/height
            // (sometimes 100, 1024, etc.) and would overflow the 32px wrapper.
            // We inject style="width:100%;height:100%" into the <svg> root and
            // strip any existing width/height/style so the icon scales to the
            // wrapper. The viewBox is kept (essential for scaling).
            $svg = preg_replace_callback(
                '/<svg\b([^>]*)>/i',
                function ($m) {
                    $attrs = $m[1];
                    $attrs = preg_replace('/\s(?:width|height|style)\s*=\s*"[^"]*"/i', '', $attrs);
                    $attrs = preg_replace("/\s(?:width|height|style)\s*=\s*'[^']*'/i", '', $attrs);
                    return '<svg' . $attrs . ' style="width:100%;height:100%;display:block" preserveAspectRatio="xMidYMid meet">';
                },
                $svg,
                1
            );
            return '<span style="display:inline-block;width:32px;height:32px;color:' . $color . ';overflow:hidden;line-height:0;">'
                . $svg
                . '</span>';
        }
        if ($url !== '') {
            return '<img src="' . htmlspecialchars($url, ENT_QUOTES) . '" style="width:32px;height:32px;object-fit:contain;">';
        }
        return '<span style="display:inline-block;width:32px;height:32px;border-radius:4px;background:#eee;text-align:center;line-height:32px;color:#aaa;font-size:18px;">?</span>';
    }

    public function renderColorChip($value, $row)
    {
        $hex = htmlspecialchars((string) $value, ENT_QUOTES);
        return '<span style="display:inline-flex;align-items:center;gap:0.4em;">'
            . '<span style="display:inline-block;width:18px;height:18px;background:' . $hex . ';border:1px solid #ccc;border-radius:3px;"></span>'
            . '<span style="font-family:monospace;font-size:0.85em;">' . $hex . '</span>'
            . '</span>';
    }

}
