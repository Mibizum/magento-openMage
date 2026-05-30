<?php
/**
 * Container for the System Badges listing (5 fixed).
 *
 * No "Create" button is exposed (the 5 system badges are seeded via SQL).
 *
 * Compatible with PHP 5.4+.
 */
class Mibizum_Sync_Block_Adminhtml_Systemoverride extends Mage_Adminhtml_Block_Widget_Grid_Container
{
    public function __construct()
    {
        $this->_blockGroup = 'mibizum_sync';
        $this->_controller = 'adminhtml_systemoverride';
        $this->_headerText = Mage::helper('mibizum_sync')->__('System Badges');
        parent::__construct();

        // No "+ Add" (the 5 are fixed).
        $this->_removeButton('add');
    }
}
