<?php
/**
 * "Indexable attributes" grid - minimalist.
 *
 * Only shows attributes that are actually in use (at least one of searchable
 * / filterable / sortable enabled). Minimal columns: Code · Name ·
 * Searchable · Filterable · Order. Everything else (boost, facet_type, sortable,
 * enabled, badge cross-ref) lives in the edit form or is handled
 * solely from the module's logic, without exposing it in the list.
 *
 * Compatible with PHP 5.4+.
 */
class Mibizum_Sync_Block_Adminhtml_Attribute_Grid extends Mage_Adminhtml_Block_Widget_Grid
{
    public function __construct()
    {
        parent::__construct();
        $this->setId('mibizum_sync_attribute_grid');
        $this->setDefaultSort('display_order');
        $this->setDefaultDir('ASC');
        $this->setSaveParametersInSession(true);
        $this->setUseAjax(false);
    }

    protected function _prepareCollection()
    {
        $collection = Mage::getModel('mibizum_sync/attributeConfig')
            ->getCollection()
            ->joinMagentoLabel();

        // Only the attributes that contribute to the index. Those imported with
        // all flags at 0 are not listed - they are equivalent to not being configured.
        $collection->getSelect()->where(
            'main_table.is_searchable = 1 OR main_table.is_filterable = 1 OR main_table.is_sortable = 1'
        );

        $this->setCollection($collection);
        return parent::_prepareCollection();
    }

    protected function _prepareColumns()
    {
        $h = Mage::helper('mibizum_sync');

        $this->addColumn('attribute_code', array(
            'header'       => $h->__('Code'),
            'index'        => 'attribute_code',
            // The collection does a LEFT JOIN with eav_attribute (which also
            // has attribute_code); without the main_table.* prefix the WHERE of
            // the grid's filter/order is ambiguous and breaks.
            'filter_index' => 'main_table.attribute_code',
            'width'        => 200,
        ));

        $this->addColumn('display_label', array(
            'header'         => $h->__('Name'),
            'index'          => 'display_label',
            'frame_callback' => array($this, 'renderName'),
        ));

        $this->addColumn('is_searchable', array(
            'header'  => $h->__('Searchable'),
            'index'   => 'is_searchable',
            'type'    => 'options',
            'width'   => 90,
            'options' => array(0 => $h->__('No'), 1 => $h->__('Yes')),
        ));

        $this->addColumn('is_filterable', array(
            'header'  => $h->__('Filterable'),
            'index'   => 'is_filterable',
            'type'    => 'options',
            'width'   => 90,
            'options' => array(0 => $h->__('No'), 1 => $h->__('Yes')),
        ));

        $this->addColumn('display_order', array(
            'header' => $h->__('Order'),
            'index'  => 'display_order',
            'type'   => 'number',
            'width'  => 70,
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
                'url'     => array('base' => 'adminhtml/mibizum_sync_attribute/edit'),
                'field'   => 'id',
            )),
        ));

        return parent::_prepareColumns();
    }

    /**
     * Name with fallback: if the user did not set display_label, the Magento
     * label is shown (`magento_label` comes from joinMagentoLabel).
     */
    public function renderName($value, $row)
    {
        $val = trim((string) $value);
        if ($val !== '') {
            return $this->escapeHtml($val);
        }
        $fb = trim((string) $row->getMagentoLabel());
        if ($fb !== '') {
            return '<span style="color:#888;font-style:italic;">' . $this->escapeHtml($fb) . '</span>';
        }
        return '-';
    }

    protected function _prepareMassaction()
    {
        $h = Mage::helper('mibizum_sync');
        $this->setMassactionIdField('config_id');
        $this->getMassactionBlock()->setFormFieldName('config_ids');

        // One row here = one attribute in the index. Only deleting makes
        // sense in bulk: "enable/disable" is handled per row by editing
        // its Searchable/Filterable toggles.
        $this->getMassactionBlock()->addItem('delete', array(
            'label'   => $h->__('Remove'),
            'url'     => $this->getUrl('adminhtml/mibizum_sync_attribute/massDelete'),
            'confirm' => $h->__('Remove the configuration of these attributes? They will stop being indexed.'),
        ));
        return $this;
    }

    public function getRowUrl($row)
    {
        return $this->getUrl('adminhtml/mibizum_sync_attribute/edit', array('id' => $row->getId()));
    }
}
