<?php
/**
 * Block container for the attribute edit form.
 *
 * Compatible with PHP 5.4+.
 */
class Mibizum_Sync_Block_Adminhtml_Attribute_Edit extends Mage_Adminhtml_Block_Widget_Form_Container
{
    public function __construct()
    {
        $this->_blockGroup = 'mibizum_sync';
        $this->_controller = 'adminhtml_attribute';
        $this->_objectId   = 'id';

        parent::__construct();

        $this->_addButton('save_and_continue', array(
            'label'   => Mage::helper('mibizum_sync')->__('Save and Continue Edit'),
            'onclick' => 'saveAndContinueEdit()',
            'class'   => 'save',
        ), 100);

        $this->_formScripts[] = "function saveAndContinueEdit(){ editForm.submit(\$('edit_form').action+'back/edit/'); }";
    }

    public function getHeaderText()
    {
        $model = Mage::registry('mibizum_sync_attribute_config');
        if ($model && $model->getId()) {
            return Mage::helper('mibizum_sync')->__("Edit attribute: %s", $model->getAttributeCode());
        }
        return Mage::helper('mibizum_sync')->__('New attribute');
    }

    /**
     * Override: the block's `_controller` (adminhtml_attribute) does not match
     * the real URL (mibizum_sync_attribute). Return the correct URLs.
     */
    public function getBackUrl()
    {
        return $this->getUrl('adminhtml/system_config/edit', array('section' => 'mibizum_sync_attributes'));
    }

    public function getSaveUrl()
    {
        return $this->getUrl('*/mibizum_sync_attribute/save');
    }

    public function getDeleteUrl()
    {
        return $this->getUrl('*/mibizum_sync_attribute/delete', array(
            'id' => $this->getRequest()->getParam('id'),
        ));
    }
}
