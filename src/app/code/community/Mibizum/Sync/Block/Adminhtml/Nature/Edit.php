<?php
/**
 * Edit form container (header with Save / Save and Continue / Delete buttons).
 *
 * Compatible with PHP 5.4+.
 */
class Mibizum_Sync_Block_Adminhtml_Nature_Edit extends Mage_Adminhtml_Block_Widget_Form_Container
{
    public function __construct()
    {
        $this->_blockGroup = 'mibizum_sync';
        $this->_controller = 'adminhtml_nature';
        parent::__construct();

        $this->_addButton('save_and_continue', array(
            'label'   => Mage::helper('mibizum_sync')->__('Save and Continue Edit'),
            'onclick' => 'saveAndContinueEdit()',
            'class'   => 'save',
        ), 100);

        $this->_formScripts[] = "
            function saveAndContinueEdit() {
                editForm.submit($('edit_form').action + 'back/edit/');
            }
        ";

        $model = Mage::registry('current_nature');
        if ($model && $model->getId()) {
            $this->_updateButton('delete', 'label', Mage::helper('mibizum_sync')->__('Delete badge'));
        } else {
            $this->_removeButton('delete');
        }
    }

    public function getHeaderText()
    {
        $model = Mage::registry('current_nature');
        if ($model && $model->getId()) {
            return Mage::helper('mibizum_sync')->__('Edit badge: %s', htmlspecialchars($model->getLabel(), ENT_QUOTES));
        }
        return Mage::helper('mibizum_sync')->__('Create category badge');
    }

    /**
     * Override: after Back, route to the embedded system_config instead of the
     * standalone controller index.
     */
    public function getBackUrl()
    {
        return $this->getUrl('adminhtml/system_config/edit', array('section' => 'mibizum_sync_badges'));
    }
}
