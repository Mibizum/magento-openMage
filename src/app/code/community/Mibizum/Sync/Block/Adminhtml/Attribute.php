<?php
/**
 * Block container for the attribute configuration grid.
 *
 * Compatible with PHP 5.4+.
 */
class Mibizum_Sync_Block_Adminhtml_Attribute extends Mage_Adminhtml_Block_Widget_Grid_Container
{
    public function __construct()
    {
        $this->_blockGroup = 'mibizum_sync';
        $this->_controller = 'adminhtml_attribute';
        $this->_headerText = Mage::helper('mibizum_sync')->__('Indexable attributes');
        $this->_addButtonLabel = Mage::helper('mibizum_sync')->__('Add attribute');
        parent::__construct();
    }

    /**
     * Override: the block's `_controller` ('adminhtml_attribute') does not match
     * the controller's real URL ('mibizum_sync_attribute'). The parent would use
     * the _controller-based route, which would produce a 404. Return the real URL.
     */
    public function getCreateUrl()
    {
        return $this->getUrl('*/mibizum_sync_attribute/new');
    }
}
