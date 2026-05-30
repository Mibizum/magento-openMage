<?php
/**
 * Edit form for a system badge.
 *
 * Gathers ALL of the badge configuration in one place: behavior (enabled,
 * visible text, threshold/days) and visual appearance (color, icon,
 * position, shape). Behavior is stored in core_config_data
 * (mibizum_sync/badges/*); appearance lives in the overrides table.
 *
 * Compatible with PHP 5.4+.
 */
class Mibizum_Sync_Block_Adminhtml_Systemoverride_Edit_Form extends Mage_Adminhtml_Block_Widget_Form
{
    protected function _prepareForm()
    {
        $h = Mage::helper('mibizum_sync');
        /** @var Mibizum_Sync_Model_SystemOverride $model */
        $model = Mage::registry('current_system_override');

        $form = new Varien_Data_Form(array(
            'id'      => 'edit_form',
            'action'  => $this->getUrl('*/*/save', array('id' => $model->getId())),
            'method'  => 'post',
            'enctype' => 'multipart/form-data',
        ));
        $form->setUseContainer(true);

        // -------- Behavior: when it shows and with what text --------
        $set = $form->addFieldset('badge_fieldset', array(
            'legend' => $h->__('Behavior'),
        ));
        $set->addField('id', 'hidden', array('name' => 'id', 'value' => $model->getId()));
        $set->addField('kind_label', 'note', array(
            'label' => $h->__('Kind'),
            'text'  => '<strong>' . htmlspecialchars($model->getKindLabel(), ENT_QUOTES) . '</strong>'
                . ' <span style="font-family:monospace;color:#888;">(' . htmlspecialchars($model->getKind(), ENT_QUOTES) . ')</span>',
        ));
        $set->addField('cfg_enabled', 'select', array(
            'label'  => $h->__('Enabled'),
            'name'   => 'cfg_enabled',
            'values' => array(
                array('value' => 1, 'label' => $h->__('Yes')),
                array('value' => 0, 'label' => $h->__('No')),
            ),
            'value'  => $model->isVisibleEnabled() ? 1 : 0,
            'note'   => $h->__('If disabled, this badge does not appear in search results.'),
        ));
        $set->addField('cfg_label', 'text', array(
            'label' => $h->__('Visible text'),
            'name'  => 'cfg_label',
            'value' => $model->getVisibleLabel(),
            'note'  => $h->__('Text shown on the badge, over the product image.'),
        ));

        $kind = $model->getKind();
        if ($kind === Mibizum_Sync_Model_SystemOverride::KIND_STOCK_LOW) {
            $set->addField('cfg_threshold', 'text', array(
                'label' => $h->__('Stock threshold'),
                'name'  => 'cfg_threshold',
                'class' => 'validate-digits',
                'value' => (int) Mage::getStoreConfig('mibizum_sync/badges/low_stock_threshold'),
                'note'  => $h->__('The badge appears if the stock is greater than 0 and less than or equal to this value.'),
            ));
        } elseif ($kind === Mibizum_Sync_Model_SystemOverride::KIND_NEW) {
            $set->addField('cfg_days', 'text', array(
                'label' => $h->__('Days as "new"'),
                'name'  => 'cfg_days',
                'class' => 'validate-digits',
                'value' => (int) Mage::getStoreConfig('mibizum_sync/badges/new_days'),
                'note'  => $h->__('The badge appears if the product was created this many days ago or less.'),
            ));
        }

        // -------- Visual appearance (the only thing editable here) --------
        $aspectSet = $form->addFieldset('aspect_fieldset', array(
            'legend' => $h->__('Visual appearance'),
        ));

        $aspectSet->addField('aspect_widget', 'note', array(
            'label' => '',
            'text'  => $this->getLayout()
                ->createBlock('adminhtml/template')
                ->setTemplate('mibizum_sync/systemoverride/system_badge_form.phtml')
                ->setBadgeId($model->getId())
                ->setBadgeModel($model)
                ->toHtml(),
        ));

        $aspectSet->addField('sort_priority', 'text', array(
            'label' => $h->__('Sort priority'),
            'name'  => 'sort_priority',
            'class' => 'validate-digits',
            'note'  => $h->__('When several system badges share the same position, they are stacked by ascending priority. Default 100.'),
            'value' => $model->getSortPriority() !== null && $model->getSortPriority() !== ''
                ? $model->getSortPriority() : 100,
        ));

        // Load values (or setFormData if it came from a failed validation).
        $session = Mage::getSingleton('adminhtml/session');
        $values  = $model->getData();

        // Inject the cfg_* values into $values: they live in core_config_data,
        // not in the model table, so they are not in $model->getData(). Without
        // this, Varien_Data_Form::setValues() (which sets missing fields to
        // NULL) would wipe the initial values we set in addField.
        $values['cfg_enabled'] = $model->isVisibleEnabled() ? 1 : 0;
        $values['cfg_label']   = $model->getVisibleLabel();
        if ($kind === Mibizum_Sync_Model_SystemOverride::KIND_STOCK_LOW) {
            $values['cfg_threshold'] = (int) Mage::getStoreConfig('mibizum_sync/badges/low_stock_threshold');
        } elseif ($kind === Mibizum_Sync_Model_SystemOverride::KIND_NEW) {
            $values['cfg_days'] = (int) Mage::getStoreConfig('mibizum_sync/badges/new_days');
        }

        if ($session->getFormData()) {
            $values = array_merge($values, $session->getFormData());
            $session->setFormData(null);
        }
        $form->setValues($values);

        $this->setForm($form);
        return parent::_prepareForm();
    }
}
