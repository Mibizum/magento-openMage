<?php
/**
 * Container for the Category Badges listing screen.
 *
 * Compatible with PHP 5.4+.
 */
class Mibizum_Sync_Block_Adminhtml_Nature extends Mage_Adminhtml_Block_Widget_Grid_Container
{
    public function __construct()
    {
        $this->_blockGroup = 'mibizum_sync';
        $this->_controller = 'adminhtml_nature';
        $this->_headerText = Mage::helper('mibizum_sync')->__('Category Badges');
        $this->_addButtonLabel = Mage::helper('mibizum_sync')->__('+ Create new badge');
        parent::__construct();
    }

    /**
     * Absolute URL to the real controller, not the default `*\/*\/new` route,
     * which would break when this container is rendered inline from
     * system_config.
     */
    public function getCreateUrl()
    {
        return $this->getUrl('adminhtml/mibizum_sync_nature/new');
    }
}
