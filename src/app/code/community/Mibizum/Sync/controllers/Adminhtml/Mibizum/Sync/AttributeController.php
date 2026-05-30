<?php
/**
 * Mibizum_Sync_Adminhtml_Mibizum_Sync_AttributeController
 *
 * Admin endpoint to manage the indexable attribute configuration.
 * Path: MIBIZUM > Search > Indexable attributes (defined in config.xml).
 *
 * URL: /mcpanel/mibizum_sync_attribute/<action>/key/<formkey>/
 * Magento 1 admin convention: 2 underscores in the URL (mibizum_sync_attribute)
 * require 3 path levels under controllers/Adminhtml/ (Mibizum/Sync/Attribute).
 *
 *  - indexAction       Grid list (only attributes with at least one flag on).
 *  - editAction        Form to edit an entry.
 *  - newAction         Alias of edit; the form uses a dropdown of available
 *                      Magento attributes (excludes the already configured ones).
 *  - saveAction        Saves the form; validates that at least Searchable or
 *                      Filterable is on and auto-assigns facet_type='multiselect'
 *                      when Filterable=1.
 *  - deleteAction      Deletes a row (attribute stops being indexed).
 *  - massDeleteAction  Delete selection.
 *
 * Compatible with PHP 5.4+.
 */
class Mibizum_Sync_Adminhtml_Mibizum_Sync_AttributeController extends Mage_Adminhtml_Controller_Action
{
    protected function _isAllowed()
    {
        return Mage::getSingleton('admin/session')->isAllowed('mibizum_sync/search/attributes');
    }

    protected function _initAction()
    {
        $this->loadLayout()
            ->_setActiveMenu('mibizum_sync/search/attributes')
            ->_addBreadcrumb(
                $this->__('MIBIZUM Labs'),
                $this->__('MIBIZUM Labs')
            )
            ->_addBreadcrumb(
                $this->__('Search'),
                $this->__('Search')
            )
            ->_addBreadcrumb(
                $this->__('Indexable attributes'),
                $this->__('Indexable attributes')
            );
        return $this;
    }

    public function indexAction()
    {
        $this->_initAction()
            ->_addContent($this->getLayout()->createBlock('mibizum_sync/adminhtml_attribute'))
            ->renderLayout();
    }

    public function editAction()
    {
        $configId = (int) $this->getRequest()->getParam('id');
        $model = Mage::getModel('mibizum_sync/attributeConfig');
        if ($configId) {
            $model->load($configId);
            if (!$model->getId()) {
                Mage::getSingleton('adminhtml/session')->addError($this->__('The requested configuration does not exist.'));
                $this->_redirect('adminhtml/system_config/edit', array('section' => 'mibizum_sync_attributes'));
                return;
            }
        }
        Mage::register('mibizum_sync_attribute_config', $model);

        $this->_initAction()
            ->_addContent($this->getLayout()->createBlock('mibizum_sync/adminhtml_attribute_edit'))
            ->renderLayout();
    }

    public function newAction()
    {
        $this->_forward('edit');
    }

    public function saveAction()
    {
        $data = $this->getRequest()->getPost();
        if (empty($data)) {
            $this->_redirect('adminhtml/system_config/edit', array('section' => 'mibizum_sync_attributes'));
            return;
        }

        $model = Mage::getModel('mibizum_sync/attributeConfig');
        if (!empty($data['config_id'])) {
            $model->load((int) $data['config_id']);
        }

        // Snapshot BEFORE applying - to know whether the flags that affect the
        // index (attribute_code / is_searchable / is_filterable / display_label
        // / display_order) actually change. If not, we do not enqueue a reindex.
        $oldFingerprint = $this->_attributeFingerprint($model->getId() ? $model : null);

        // Validation: the code is required (when creating it comes from the
        // dropdown; when editing, the hidden field provides it).
        $code = isset($data['attribute_code']) ? trim((string) $data['attribute_code']) : '';
        if ($code === '') {
            Mage::getSingleton('adminhtml/session')->addError(
                $this->__('You must choose an attribute.')
            );
            Mage::getSingleton('adminhtml/session')->setFormData($data);
            $this->_redirect('*/*/edit', array('id' => $model->getId()));
            return;
        }

        // Validation: at least one of Searchable or Filterable must be on
        // (otherwise the row contributes nothing to the index).
        $isSearchable = !empty($data['is_searchable']) ? 1 : 0;
        $isFilterable = !empty($data['is_filterable']) ? 1 : 0;
        if (!$isSearchable && !$isFilterable) {
            Mage::getSingleton('adminhtml/session')->addError(
                $this->__('Enable at least Searchable or Filterable so the attribute contributes to the search.')
            );
            Mage::getSingleton('adminhtml/session')->setFormData($data);
            $this->_redirect('*/*/edit', array('id' => $model->getId()));
            return;
        }

        // Assignment of user-editable fields.
        $model->setAttributeCode($code);
        $model->setDisplayLabel(isset($data['display_label']) ? trim((string) $data['display_label']) : '');
        $model->setIsSearchable($isSearchable);
        $model->setIsFilterable($isFilterable);
        $model->setDisplayOrder((int) (isset($data['display_order']) ? $data['display_order'] : 100));

        // Internally managed fields (not exposed to the user):
        //  - facet_type: derived from is_filterable. 'multiselect' is the
        //    reasonable default; it covers 95% of cases. If a specific
        //    attribute needs another type, adjust it via DB.
        $model->setFacetType($isFilterable ? 'multiselect' : null);
        //  - is_sortable: hidden, default 0. To enable it, via DB.
        if ($model->getIsSortable() === null) $model->setIsSortable(0);
        //  - searchable_boost: hidden, default 1.00.
        if ($model->getSearchableBoost() === null) $model->setSearchableBoost('1.00');
        //  - enabled: a row here = active. Always 1.
        $model->setEnabled(1);

        try {
            $model->save();
            Mibizum_Sync_Model_Indexer_ProductMapper::resetCache();
            Mage::getSingleton('adminhtml/session')->addSuccess(
                $this->__('Attribute saved: %s', $model->getAttributeCode())
            );

            // If the change affects the index (flags / label / order / new
            // attribute), enqueue a full reindex. Changing an attribute_code
            // changes which product fields go to the index, so all products
            // need to be rewritten. If nothing relevant changed, skip.
            $newFingerprint = $this->_attributeFingerprint($model);
            if ($oldFingerprint !== $newFingerprint) {
                try {
                    /** @var Mibizum_Sync_Helper_Data $h */
                    $h = Mage::helper('mibizum_sync');
                    $enqueued = $h->enqueueAllProductsForReindex('attribute_config_changed');
                    if ($enqueued > 0) {
                        Mage::getSingleton('adminhtml/session')->addNotice(
                            $this->__(
                                '%d products pending update in search.',
                                $enqueued
                            )
                        );
                    }
                } catch (Exception $e) {
                    Mage::helper('mibizum_sync')->log(
                        'AttributeController saveAction enqueue failed: ' . $e->getMessage(),
                        Zend_Log::WARN
                    );
                }
            }

            if ($this->getRequest()->getParam('back')) {
                $this->_redirect('*/*/edit', array('id' => $model->getId()));
                return;
            }
            $this->_redirect('adminhtml/system_config/edit', array('section' => 'mibizum_sync_attributes'));
            return;
        } catch (Exception $e) {
            Mage::getSingleton('adminhtml/session')->addError($e->getMessage());
            Mage::getSingleton('adminhtml/session')->setFormData($data);
            $this->_redirect('*/*/edit', array('id' => $model->getId()));
        }
    }

    public function deleteAction()
    {
        $configId = (int) $this->getRequest()->getParam('id');
        if (!$configId) {
            $this->_redirect('adminhtml/system_config/edit', array('section' => 'mibizum_sync_attributes'));
            return;
        }
        try {
            Mage::getModel('mibizum_sync/attributeConfig')->setId($configId)->delete();
            Mibizum_Sync_Model_Indexer_ProductMapper::resetCache();
            Mage::getSingleton('adminhtml/session')->addSuccess($this->__('Attribute deleted.'));
            $this->_enqueueAfterAttrChange('attribute_deleted');
        } catch (Exception $e) {
            Mage::getSingleton('adminhtml/session')->addError($e->getMessage());
        }
        $this->_redirect('adminhtml/system_config/edit', array('section' => 'mibizum_sync_attributes'));
    }

    public function massDeleteAction()
    {
        $ids = (array) $this->getRequest()->getParam('config_ids', array());
        $count = 0;
        foreach ($ids as $id) {
            try {
                Mage::getModel('mibizum_sync/attributeConfig')->setId((int) $id)->delete();
                $count++;
            } catch (Exception $e) {
                Mage::getSingleton('adminhtml/session')->addError($e->getMessage());
            }
        }
        if ($count > 0) {
            Mibizum_Sync_Model_Indexer_ProductMapper::resetCache();
            Mage::getSingleton('adminhtml/session')->addSuccess(
                $this->__('%d attributes deleted.', $count)
            );
            $this->_enqueueAfterAttrChange('attribute_mass_deleted');
        }
        $this->_redirect('adminhtml/system_config/edit', array('section' => 'mibizum_sync_attributes'));
    }

    /**
     * Fingerprint of the attribute_config: if two snapshots are equal, the
     * attribute flags did not change and enqueuing a reindex is not worth it.
     *
     * @param Mibizum_Sync_Model_AttributeConfig|null $m
     * @return string
     */
    protected function _attributeFingerprint($m)
    {
        if (!$m || !$m->getId()) {
            // NEW attribute: anything counts as a change.
            return 'NEW_' . microtime(true);
        }
        return md5(implode('|', array(
            (string) $m->getAttributeCode(),
            (string) $m->getDisplayLabel(),
            (int)    $m->getIsSearchable(),
            (int)    $m->getIsFilterable(),
            (int)    $m->getDisplayOrder(),
            (string) $m->getFacetType(),
            (int)    $m->getIsSortable(),
            (string) $m->getSearchableBoost(),
            (int)    $m->getEnabled(),
        )));
    }

    /**
     * Enqueues a full reindex after a change in the attribute config. Changing
     * an attribute_code changes WHICH product fields go to the index, so all
     * documents need to be rewritten. Warns the user with a notice (the global
     * banner takes care of the rest from any screen).
     */
    protected function _enqueueAfterAttrChange($reason)
    {
        try {
            /** @var Mibizum_Sync_Helper_Data $h */
            $h = Mage::helper('mibizum_sync');
            $enqueued = $h->enqueueAllProductsForReindex($reason);
            if ($enqueued > 0) {
                Mage::getSingleton('adminhtml/session')->addNotice(
                    $this->__('%d products pending update in search.', $enqueued)
                );
            }
        } catch (Exception $e) {
            Mage::helper('mibizum_sync')->log(
                'AttributeController _enqueueAfterAttrChange failed: ' . $e->getMessage(),
                Zend_Log::WARN
            );
        }
    }

}
