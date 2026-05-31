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
            $this->_makeDeletePost(
                $this->getUrl('adminhtml/mibizum_sync_nature/delete', array('id' => $model->getId())),
                Mage::helper('mibizum_sync')->__('Delete this badge?')
            );
        } else {
            $this->_removeButton('delete');
        }
    }

    /**
     * Replace the inherited "Delete" button's default GET navigation with a
     * POST + form_key submit (CSRF defense-in-depth on top of the admin secret
     * URL key). The matching controller action rejects anything that is not a
     * POST carrying a valid form_key.
     *
     * @param string $url
     * @param string $confirm
     */
    protected function _makeDeletePost($url, $confirm)
    {
        $formKey   = Mage::getSingleton('core/session')->getFormKey();
        $confirmJs = Mage::helper('core')->jsQuoteEscape($confirm);
        $this->_updateButton(
            'delete',
            'onclick',
            "mibizumPostDelete('" . $url . "','" . $formKey . "','" . $confirmJs . "'); return false;"
        );
        $this->_formScripts[] = "
            function mibizumPostDelete(url, formKey, message) {
                if (!confirm(message)) { return false; }
                var f = document.createElement('form');
                f.method = 'POST';
                f.action = url;
                var i = document.createElement('input');
                i.type = 'hidden'; i.name = 'form_key'; i.value = formKey;
                f.appendChild(i);
                document.body.appendChild(f);
                f.submit();
                return false;
            }
        ";
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
