<?php
/**
 * Container for the Attribute Badge edit form.
 *
 * Compatible with PHP 5.4+.
 */
class Mibizum_Sync_Block_Adminhtml_Attributebadge_Edit extends Mage_Adminhtml_Block_Widget_Form_Container
{
    public function __construct()
    {
        $this->_blockGroup = 'mibizum_sync';
        $this->_controller = 'adminhtml_attributebadge';
        parent::__construct();

        $this->_addButton('save_and_continue', array(
            'label'   => Mage::helper('mibizum_sync')->__('Save and continue'),
            'onclick' => 'saveAndContinueEdit()',
            'class'   => 'save',
        ), 100);

        $this->_formScripts[] = "
            function saveAndContinueEdit() {
                editForm.submit($('edit_form').action + 'back/edit/');
            }
        ";

        $model = Mage::registry('current_attribute_badge');
        if ($model && $model->getId()) {
            $this->_updateButton('delete', 'label', Mage::helper('mibizum_sync')->__('Remove badge'));
        } else {
            $this->_removeButton('delete');
        }
    }

    public function getHeaderText()
    {
        $model = Mage::registry('current_attribute_badge');
        if ($model && $model->getId()) {
            return Mage::helper('mibizum_sync')->__(
                'Edit attribute badge: %s',
                htmlspecialchars($model->getAttributeCode(), ENT_QUOTES)
            );
        }
        return Mage::helper('mibizum_sync')->__('Create attribute badge');
    }

    /**
     * Override: after Back, redirect to the embedded system_config instead of
     * the standalone controller's index.
     */
    public function getBackUrl()
    {
        return $this->getUrl('adminhtml/system_config/edit', array('section' => 'mibizum_sync_badges'));
    }
}
