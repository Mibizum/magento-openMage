<?php
/**
 * Edit form for an indexable attribute - minimalist.
 *
 * Only exposes what the user actually decides:
 *  - Code (dropdown of available Magento attributes when creating;
 *    read-only when editing - changing the code of an existing row
 *    would break the index's traceability).
 *  - Name (display_label, optional; falls back to the Magento name).
 *  - Searchable (is_searchable).
 *  - Filterable (is_filterable). When on, the module assigns
 *    facet_type='multiselect' by default (handled in saveAction).
 *  - Order (display_order).
 *
 * Everything else (searchable_boost, facet_type, is_sortable, enabled, notes)
 * is handled internally or kept at its defaults.
 *
 * Compatible with PHP 5.4+.
 */
class Mibizum_Sync_Block_Adminhtml_Attribute_Edit_Form extends Mage_Adminhtml_Block_Widget_Form
{
    protected function _prepareForm()
    {
        $h     = Mage::helper('mibizum_sync');
        $model = Mage::registry('mibizum_sync_attribute_config');

        $form = new Varien_Data_Form(array(
            'id'      => 'edit_form',
            'action'  => $this->getUrl('*/*/save', array('id' => $model->getId())),
            'method'  => 'post',
        ));
        $form->setUseContainer(true);
        $this->setForm($form);

        $fs = $form->addFieldset('attribute_config_fieldset', array(
            'legend' => $h->__('Attribute'),
        ));

        if ($model->getId()) {
            $fs->addField('config_id', 'hidden', array('name' => 'config_id'));
            // When editing, the code is the identity: read-only.
            $fs->addField('attribute_code_note', 'note', array(
                'label' => $h->__('Code'),
                'text'  => '<strong style="font-family:monospace;">'
                        . $this->escapeHtml($model->getAttributeCode()) . '</strong>',
            ));
            // Also send it as hidden so the POST keeps it.
            $fs->addField('attribute_code', 'hidden', array(
                'name'  => 'attribute_code',
                'value' => $model->getAttributeCode(),
            ));
        } else {
            // Searchable combo (reusable mibizum-searchable-select component).
            // The admin's native <select> with 147 attributes was unmanageable;
            // now it filters as you type. We preload the options into
            // data-mibizum-options (client-side mode, no AJAX).
            $jsonOpts = array();
            foreach ($this->_getAvailableAttributeOptions() as $o) {
                if (!isset($o['value']) || $o['value'] === '') continue;
                $jsonOpts[] = array('value' => $o['value'], 'label' => $o['label']);
            }
            $placeholder = $h->__('Type to search among the Magento attributes...');
            $note        = $h->__('Choose one of the Magento attributes that is not yet configured here.');
            $widget = '<div class="mibizum-searchable-select" '
                    . 'data-mibizum-empty="' . $this->escapeHtml($h->__('No matches')) . '" '
                    . 'data-mibizum-options="'
                    . $this->escapeHtml(json_encode($jsonOpts)) . '">'
                    . '<input type="text" class="mss-input input-text" '
                    . 'placeholder="' . $this->escapeHtml($placeholder) . '" autocomplete="off" />'
                    . '<input type="hidden" class="mss-value" name="attribute_code" value="" />'
                    . '<ul class="mss-list" style="display:none;"></ul>'
                    . '</div>'
                    . '<p class="note"><span>' . $this->escapeHtml($note) . '</span></p>';
            $fs->addField('attribute_code_widget', 'note', array(
                'label' => $h->__('Code'),
                'text'  => $widget,
            ));
        }

        $fs->addField('display_label', 'text', array(
            'name'  => 'display_label',
            'label' => $h->__('Name'),
            'note'  => $h->__('How it is shown to the customer. If you leave it empty, the name Magento gives this attribute is used.'),
        ));

        $fs->addField('is_searchable', 'select', array(
            'name'   => 'is_searchable',
            'label'  => $h->__('Searchable'),
            'values' => array(
                array('value' => 1, 'label' => $h->__('Yes')),
                array('value' => 0, 'label' => $h->__('No')),
            ),
            'note'   => $h->__('If enabled, the text of this attribute counts when the customer types in the search box.'),
        ));

        $fs->addField('is_filterable', 'select', array(
            'name'   => 'is_filterable',
            'label'  => $h->__('Filterable'),
            'values' => array(
                array('value' => 0, 'label' => $h->__('No')),
                array('value' => 1, 'label' => $h->__('Yes')),
            ),
            'note'   => $h->__('If enabled, it appears as a sidebar filter in the results (with counts). Enable at least one of Searchable or Filterable.'),
        ));

        $fs->addField('display_order', 'text', array(
            'name'  => 'display_order',
            'label' => $h->__('Order'),
            'class' => 'validate-digits',
            'note'  => $h->__('Number (lower = appears earlier in the filters sidebar). Default 100.'),
        ));

        if ($model->getId()) {
            $form->setValues($model->getData());
        } else {
            $form->setValues(array(
                'is_searchable' => 0,
                'is_filterable' => 1,
                'display_order' => 100,
            ));
        }

        return parent::_prepareForm();
    }

    /**
     * List of Magento attributes (catalog_product) available to add,
     * excluding the ones already configured here.
     *
     * @return array<int,array{value:string,label:string}>
     */
    protected function _getAvailableAttributeOptions()
    {
        $existing = array_flip((array) Mage::getModel('mibizum_sync/attributeConfig')
            ->getCollection()
            ->getColumnValues('attribute_code'));

        $opts = array(array('value' => '', 'label' => Mage::helper('mibizum_sync')->__('- choose an attribute -')));

        try {
            $entityType = Mage::getModel('eav/entity_type')->loadByCode('catalog_product');
            $attrs = Mage::getResourceModel('eav/entity_attribute_collection')
                ->setEntityTypeFilter($entityType->getId())
                ->setOrder('frontend_label', 'ASC');
            foreach ($attrs as $a) {
                $code = (string) $a->getAttributeCode();
                if ($code === '' || isset($existing[$code])) {
                    continue;
                }
                $label = trim((string) $a->getFrontendLabel());
                if ($label === '') { $label = $code; }
                $opts[] = array(
                    'value' => $code,
                    'label' => $label . ' (' . $code . ')',
                );
            }
        } catch (Exception $e) {
            Mage::logException($e);
        }

        return $opts;
    }
}
