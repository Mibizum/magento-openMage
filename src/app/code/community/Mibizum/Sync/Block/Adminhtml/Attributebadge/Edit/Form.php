<?php
/**
 * Attribute Badge edit form.
 *
 * Visually identical to the nature form but without category assignment.
 * Replaces categories with an attribute_code autocomplete.
 *
 * Compatible with PHP 5.4+.
 */
class Mibizum_Sync_Block_Adminhtml_Attributebadge_Edit_Form extends Mage_Adminhtml_Block_Widget_Form
{
    protected function _prepareForm()
    {
        $h = Mage::helper('mibizum_sync');
        /** @var Mibizum_Sync_Model_AttributeBadge $model */
        $model = Mage::registry('current_attribute_badge');

        $form = new Varien_Data_Form(array(
            'id'      => 'edit_form',
            'action'  => $this->getUrl('*/*/save', array('id' => $model->getId())),
            'method'  => 'post',
            'enctype' => 'multipart/form-data',
        ));
        $form->setUseContainer(true);

        $base = $form->addFieldset('base_fieldset', array(
            'legend' => $h->__('Attribute'),
        ));
        if ($model->getId()) {
            $base->addField('id', 'hidden', array('name' => 'id'));
        }

        // Reusable searchable combo (mibizum-searchable-select, AJAX mode).
        // Replaces the previous bespoke picker (1 input + ul + div + hidden with
        // dedicated CSS and JS). The searchAttributes endpoint already returns
        // {code, frontend_label, frontend_input}; the widget adapter natively
        // maps {code, frontend_label} to {value, label}.
        $code  = (string) $model->getAttributeCode();
        $label = '';
        if ($code !== '') {
            try {
                $eav = Mage::getSingleton('eav/config')->getAttribute('catalog_product', $code);
                if ($eav && $eav->getId()) {
                    $fl    = trim((string) $eav->getFrontendLabel());
                    $label = ($fl !== '' ? $fl . ' (' . $code . ')' : $code);
                } else {
                    $label = $code;
                }
            } catch (Exception $e) {
                $label = $code;
            }
        }
        $searchUrl = $this->getUrl('adminhtml/mibizum_sync_attributebadge/searchAttributes');
        $widget = '<div class="mibizum-searchable-select" '
                . 'data-mibizum-empty="' . $this->escapeHtml($h->__('No matches')) . '" '
                . 'data-mibizum-url="' . $this->escapeHtml($searchUrl) . '">'
                . '<input type="text" class="mss-input input-text" '
                . 'placeholder="' . $this->escapeHtml($h->__('Type an attribute_code or frontend_label…')) . '" '
                . 'value="' . $this->escapeHtml($label) . '" autocomplete="off" />'
                . '<input type="hidden" class="mss-value" name="attribute_code" '
                . 'value="' . $this->escapeHtml($code) . '" />'
                . '<ul class="mss-list" style="display:none;"></ul>'
                . '</div>';
        $base->addField('attribute_code_widget', 'note', array(
            'label' => $h->__('Attribute'),
            'text'  => $widget,
        ));

        $base->addField('label', 'text', array(
            'label' => $h->__('Fallback label (optional)'),
            'name'  => 'label',
            'note'  => $h->__('Only used if the product has no value for this attribute (rare). Normally left empty so the badge shows the product\'s actual value.'),
        ));

        $base->addField('sort_priority', 'text', array(
            'label' => $h->__('Sort priority'),
            'name'  => 'sort_priority',
            'class' => 'validate-digits',
            'note'  => $h->__('If several attribute badges land in the same corner, they stack by ascending priority. Default 100.'),
            'value' => $model->getSortPriority() !== null && $model->getSortPriority() !== ''
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

        $aspectSet = $form->addFieldset('aspect_fieldset', array(
            'legend' => $h->__('Visual appearance'),
        ));
        $aspectSet->addField('aspect_widget', 'note', array(
            'label' => '',
            'text'  => $this->getLayout()
                ->createBlock('adminhtml/template')
                ->setTemplate('mibizum_sync/attributebadge/attribute_badge_form.phtml')
                ->setBadgeId($model->getId())
                ->setBadgeModel($model)
                ->toHtml(),
        ));

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
