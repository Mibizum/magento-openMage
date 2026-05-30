<?php
/**
 * Grid for the System Badges listing (5 fixed).
 *
 * No filters, no pagination, no mass actions. It just shows the 5 rows
 * with icon + color chip and allows editing.
 *
 * Compatible with PHP 5.4+.
 */
class Mibizum_Sync_Block_Adminhtml_Systemoverride_Grid extends Mage_Adminhtml_Block_Widget_Grid
{
    public function __construct()
    {
        parent::__construct();
        $this->setId('mibizum_sync_systemoverride_grid');
        $this->setDefaultSort('sort_priority');
        $this->setDefaultDir('ASC');
        $this->setSaveParametersInSession(false);
        $this->setUseAjax(false);
        $this->setPagerVisibility(false);
        $this->setFilterVisibility(false);
    }

    protected function _prepareCollection()
    {
        $collection = Mage::getModel('mibizum_sync/systemOverride')->getCollection();
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

        $this->addColumn('kind', array(
            'header'         => $h->__('Kind'),
            'index'          => 'kind',
            'width'          => 140,
            'sortable'       => false,
            'filter'         => false,
            'frame_callback' => array($this, 'renderKindLabel'),
        ));

        $this->addColumn('visible_label', array(
            'header'         => $h->__('Visible text'),
            'index'          => 'kind',
            'sortable'       => false,
            'filter'         => false,
            'frame_callback' => array($this, 'renderVisibleLabel'),
        ));

        $this->addColumn('color_hex', array(
            'header'         => $h->__('Color'),
            'index'          => 'color_hex',
            'width'          => 120,
            'sortable'       => false,
            'filter'         => false,
            'frame_callback' => array($this, 'renderColorChip'),
        ));

        $this->addColumn('position', array(
            'header'   => $h->__('Position'),
            'index'    => 'position',
            'width'    => 110,
            'sortable' => false,
            'filter'   => false,
        ));

        $this->addColumn('shape', array(
            'header'   => $h->__('Shape'),
            'index'    => 'shape',
            'width'    => 100,
            'sortable' => false,
            'filter'   => false,
        ));

        $this->addColumn('display_mode', array(
            'header'   => $h->__('Display'),
            'index'    => 'display_mode',
            'width'    => 110,
            'sortable' => false,
            'filter'   => false,
        ));

        $this->addColumn('sort_priority', array(
            'header'   => $h->__('Priority'),
            'index'    => 'sort_priority',
            'type'     => 'number',
            'width'    => 80,
            'filter'   => false,
        ));

        $this->addColumn('enabled_in_config', array(
            'header'         => $h->__('Enabled'),
            'index'          => 'kind',
            'width'          => 80,
            'sortable'       => false,
            'filter'         => false,
            'frame_callback' => array($this, 'renderEnabledInConfig'),
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
                'url'     => array('base' => 'adminhtml/mibizum_sync_systemoverride/edit'),
                'field'   => 'id',
            )),
        ));

        return parent::_prepareColumns();
    }

    /**
     * Enable / disable in bulk. Deleting is NOT allowed (the 5 system badges
     * are fixed). The "enabled" flag for each one lives in config
     * (mibizum_sync/badges/<prefix>_enabled), not in the table; the controller
     * translates the mass action into config writes.
     */
    protected function _prepareMassaction()
    {
        $h = Mage::helper('mibizum_sync');
        $this->setMassactionIdField('id');
        $this->getMassactionBlock()->setFormFieldName('badge_ids');

        $this->getMassactionBlock()->addItem('enable', array(
            'label' => $h->__('Enable'),
            'url'   => $this->getUrl('adminhtml/mibizum_sync_systemoverride/massEnable'),
        ));
        $this->getMassactionBlock()->addItem('disable', array(
            'label' => $h->__('Disable'),
            'url'   => $this->getUrl('adminhtml/mibizum_sync_systemoverride/massDisable'),
        ));
        return $this;
    }

    public function getRowUrl($row)
    {
        return $this->getUrl('adminhtml/mibizum_sync_systemoverride/edit', array('id' => $row->getId()));
    }

    public function renderIconPreview($value, $row)
    {
        $svg = (string) $row->getIconSvg();
        $url = (string) $row->getIconUrl();
        $faClass = (string) $row->getIconFaClass();
        $color = htmlspecialchars((string) $row->getColorHex(), ENT_QUOTES);

        if ($faClass !== '' && preg_match('/^fa-[a-z0-9-]+$/', $faClass)) {
            $faCssUrl = htmlspecialchars(
                Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_SKIN)
                    . 'frontend/base/default/css/plumrocket/pramp/font-awesome.min.css',
                ENT_QUOTES
            );
            return '<link rel="stylesheet" href="' . $faCssUrl . '" />'
                . '<span style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;color:' . $color . ';">'
                . '<i class="fa ' . $faClass . '" style="font-size:24px;line-height:1;"></i>'
                . '</span>';
        }
        if ($svg !== '') {
            return '<span style="display:inline-block;width:32px;height:32px;color:' . $color . ';">'
                . $svg
                . '</span>';
        }
        if ($url !== '') {
            return '<img src="' . htmlspecialchars($url, ENT_QUOTES)
                . '" style="width:32px;height:32px;object-fit:contain;">';
        }
        return '<span style="display:inline-block;width:32px;height:32px;border-radius:4px;'
            . 'background:#eee;text-align:center;line-height:32px;color:#aaa;font-size:18px;">?</span>';
    }

    public function renderKindLabel($value, $row)
    {
        return '<strong>'
            . htmlspecialchars(Mibizum_Sync_Model_SystemOverride::labelFromKind($value), ENT_QUOTES)
            . '</strong>'
            . '<br><span style="font-family:monospace;font-size:0.8em;color:#888;">'
            . htmlspecialchars((string) $value, ENT_QUOTES) . '</span>';
    }

    public function renderVisibleLabel($value, $row)
    {
        return htmlspecialchars((string) $row->getVisibleLabel(), ENT_QUOTES);
    }

    public function renderColorChip($value, $row)
    {
        $hex = htmlspecialchars((string) $value, ENT_QUOTES);
        return '<span style="display:inline-flex;align-items:center;gap:0.4em;">'
            . '<span style="display:inline-block;width:18px;height:18px;background:' . $hex
            . ';border:1px solid #ccc;border-radius:3px;"></span>'
            . '<span style="font-family:monospace;font-size:0.85em;">' . $hex . '</span>'
            . '</span>';
    }

    public function renderEnabledInConfig($value, $row)
    {
        $enabled = $row->isVisibleEnabled();
        if ($enabled) {
            return '<span style="color:#1E9C3C;font-weight:bold;">' . $this->__('Yes') . '</span>';
        }
        return '<span style="color:#999;">' . $this->__('No') . '</span>';
    }
}
