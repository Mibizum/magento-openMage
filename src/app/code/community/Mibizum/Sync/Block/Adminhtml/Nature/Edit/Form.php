<?php
/**
 * Flat badge edit form.
 *
 * No tabs: a single page with sections grouped by fieldset.
 * The visual fields (color picker, position picker, shape picker, kind
 * picker, display_mode picker, icon drop-zone, category autocomplete) are
 * rendered via a single phtml template nature_form.phtml that keeps hidden
 * inputs synced with the form submit.
 *
 * Compatible with PHP 5.4+.
 */
class Mibizum_Sync_Block_Adminhtml_Nature_Edit_Form extends Mage_Adminhtml_Block_Widget_Form
{
    protected function _prepareForm()
    {
        $h = Mage::helper('mibizum_sync');
        /** @var Mibizum_Sync_Model_Nature $model */
        $model = Mage::registry('current_nature');

        $form = new Varien_Data_Form(array(
            'id'      => 'edit_form',
            'action'  => $this->getUrl('*/*/save', array('id' => $model->getId())),
            'method'  => 'post',
            'enctype' => 'multipart/form-data',
        ));
        $form->setUseContainer(true);

        // ---------------- Basic data ----------------
        $base = $form->addFieldset('base_fieldset', array(
            'legend' => $h->__('Basic data'),
        ));

        if ($model->getId()) {
            $base->addField('id', 'hidden', array('name' => 'id'));
        }

        $base->addField('label', 'text', array(
            'label'    => $h->__('Name'),
            'name'     => 'label',
            'required' => true,
            'note'     => $h->__('Badge text (e.g.: Essential Oil). For system badges you will see it as the visible label when display_mode includes text.'),
        ));

        $base->addField('slug', 'text', array(
            'label'    => $h->__('Slug'),
            'name'     => 'slug',
            'note'     => $h->__('Unique url-safe identifier. If left empty it is auto-generated from the name.'),
        ));

        $base->addField('sort_priority', 'text', array(
            'label'    => $h->__('Sort priority'),
            'name'     => 'sort_priority',
            'class'    => 'validate-digits',
            'note'     => $h->__('When a product matches several badges in the same position, they are stacked by ascending priority (lowest first). Default 100.'),
            'value'    => $model->getSortPriority() !== null && $model->getSortPriority() !== ''
                ? $model->getSortPriority() : 100,
        ));

        $base->addField('enabled', 'select', array(
            'label'  => $h->__('Enabled'),
            'name'   => 'enabled',
            'values' => array(
                array('value' => 1, 'label' => $h->__('Yes')),
                array('value' => 0, 'label' => $h->__('No')),
            ),
            'value'  => $model->getId() ? (int) $model->getEnabled() : 1,
        ));

        // ---------------- Kind and appearance ----------------
        // Rendered by a phtml template with visual pickers. The hidden inputs
        // (kind, color_hex, text_color_hex, position, shape, display_mode,
        // trigger_threshold, trigger_days) live INSIDE the template and are
        // submitted with the form.
        $aspectSet = $form->addFieldset('aspect_fieldset', array(
            'legend' => $h->__('Kind and appearance'),
        ));

        $aspectSet->addField('aspect_widget', 'note', array(
            'label' => '',
            'text'  => $this->getLayout()
                ->createBlock('adminhtml/template')
                ->setTemplate('mibizum_sync/nature/nature_form.phtml')
                ->setBadgeId($model->getId())
                ->setBadgeModel($model)
                ->toHtml(),
        ));

        // Load the model values (or setFormData if it came from a failed validation).
        $session = Mage::getSingleton('adminhtml/session');
        $values = $model->getData();
        if ($session->getFormData()) {
            $values = array_merge($values, $session->getFormData());
            $session->setFormData(null);
        }
        $form->setValues($values);

        $this->setForm($form);
        return parent::_prepareForm();
    }
}
