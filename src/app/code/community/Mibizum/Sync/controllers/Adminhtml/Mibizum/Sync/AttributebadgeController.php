<?php
/**
 * Mibizum_Sync_Adminhtml_Mibizum_Sync_AttributebadgeController
 *
 * MIBIZUM Labs > Search > Attribute badges screen.
 *
 * URL: /mcpanel/mibizum_sync_attributebadge/<action>/key/<formkey>/
 *
 * The admin picks an attribute_code (from eav_attribute) + visual config; when
 * indexing products, ProductMapper resolves the product's real value and stores
 * it as the badge label in `attribute_badges` of the index document.
 *
 * Compatible with PHP 5.4+.
 */
class Mibizum_Sync_Adminhtml_Mibizum_Sync_AttributebadgeController extends Mage_Adminhtml_Controller_Action
{
    protected function _isAllowed()
    {
        return Mage::getSingleton('admin/session')->isAllowed('mibizum_sync/search/attribute_badges');
    }

    protected function _initLayout()
    {
        $this->loadLayout()->_setActiveMenu('mibizum_sync/search/attribute_badges');
        return $this;
    }

    /**
     * CSRF guard for state-changing actions: require POST + a valid form_key
     * (defense-in-depth on top of the admin secret URL key). Returns false,
     * after queuing an error and a redirect, when the request must be rejected.
     *
     * @return bool
     */
    protected function _guardWrite()
    {
        if ($this->getRequest()->isPost() && $this->_validateFormKey()) {
            return true;
        }
        Mage::getSingleton('adminhtml/session')->addError(
            $this->__('Your session expired or the security token is invalid. Please retry.')
        );
        $this->_redirect('adminhtml/system_config/edit', array('section' => 'mibizum_sync_badges'));
        return false;
    }

    public function indexAction()
    {
        $this->_initLayout()
            ->_addContent($this->getLayout()->createBlock('mibizum_sync/adminhtml_attributebadge'))
            ->renderLayout();
    }

    public function newAction()
    {
        $this->_forward('edit');
    }

    public function editAction()
    {
        $id = (int) $this->getRequest()->getParam('id');
        /** @var Mibizum_Sync_Model_AttributeBadge $model */
        $model = Mage::getModel('mibizum_sync/attributeBadge');

        if ($id) {
            $model->load($id);
            if (!$model->getId()) {
                Mage::getSingleton('adminhtml/session')->addError($this->__('Badge not found.'));
                return $this->_redirect('adminhtml/system_config/edit', array('section' => 'mibizum_sync_badges'));
            }
        } else {
            // Support for linking from the "Indexable attributes" screen:
            // ?preset_code=origin_country pre-fills the attribute_code in the
            // form. If a badge already exists for that code, redirect to its edit.
            $presetCode = trim((string) $this->getRequest()->getParam('preset_code'));
            if ($presetCode !== '') {
                $existing = Mage::getModel('mibizum_sync/attributeBadge')
                    ->getCollection()
                    ->addFieldToFilter('attribute_code', $presetCode)
                    ->setPageSize(1)
                    ->getFirstItem();
                if ($existing && $existing->getId()) {
                    return $this->_redirect('*/*/edit', array('id' => $existing->getId()));
                }
                $model->setAttributeCode($presetCode);
            }
        }

        Mage::register('current_attribute_badge', $model);

        $this->_initLayout()
            ->_addContent($this->getLayout()->createBlock('mibizum_sync/adminhtml_attributebadge_edit'))
            ->renderLayout();
    }

    public function saveAction()
    {
        if (!$this->_guardWrite()) {
            return;
        }
        $data = $this->getRequest()->getPost();
        if (empty($data)) {
            return $this->_redirect('adminhtml/system_config/edit', array('section' => 'mibizum_sync_badges'));
        }

        $id = (int) $this->getRequest()->getParam('id');
        /** @var Mibizum_Sync_Model_AttributeBadge $model */
        $model = Mage::getModel('mibizum_sync/attributeBadge');
        if ($id) {
            $model->load($id);
            if (!$model->getId()) {
                Mage::getSingleton('adminhtml/session')->addError($this->__('Badge not found.'));
                return $this->_redirect('adminhtml/system_config/edit', array('section' => 'mibizum_sync_badges'));
            }
        }

        try {
            $code = trim((string) (isset($data['attribute_code']) ? $data['attribute_code'] : ''));
            if ($code === '') {
                throw new Exception($this->__('The attribute_code is required.'));
            }
            $model->setAttributeCode($code);
            $model->setLabel(isset($data['label']) ? trim((string) $data['label']) : null);

            $img = Mage::helper('mibizum_sync/image');
            $model->setColorHex($img->sanitizeHexColor(isset($data['color_hex']) ? $data['color_hex'] : '', '#1E9C3C'));
            $model->setTextColorHex($img->sanitizeOptionalHexColor(isset($data['text_color_hex']) ? $data['text_color_hex'] : ''));

            $allowedDisplay = array('icon_only', 'text_only', 'icon_and_text');
            $display = isset($data['display_mode']) && in_array($data['display_mode'], $allowedDisplay, true)
                ? $data['display_mode'] : 'icon_and_text';
            $model->setDisplayMode($display);

            $allowedPositions = array('top_left', 'top_right', 'bottom_left', 'bottom_right', 'below_image');
            $position = isset($data['position']) && in_array($data['position'], $allowedPositions, true)
                ? $data['position'] : 'top_right';
            $model->setPosition($position);

            $allowedShapes = array('pill', 'circle', 'square_rounded');
            $shape = isset($data['shape']) && in_array($data['shape'], $allowedShapes, true)
                ? $data['shape'] : 'pill';
            $model->setShape($shape);

            $iconSvg     = $img->sanitizeSvg(isset($data['icon_svg']) ? $data['icon_svg'] : '');
            $iconUrl     = isset($data['icon_url'])      ? trim($data['icon_url'])      : '';
            $iconFaClass = isset($data['icon_fa_class']) ? trim($data['icon_fa_class']) : '';
            if ($iconFaClass !== '' && !preg_match('/^fa-[a-z0-9-]+$/', $iconFaClass)) {
                $iconFaClass = '';
            }
            $model->setIconSvg($iconSvg !== '' ? $iconSvg : null);
            $model->setIconUrl($iconUrl !== '' ? $iconUrl : null);
            $model->setIconFaClass($iconFaClass !== '' ? $iconFaClass : null);

            $model->setSortPriority((int) (isset($data['sort_priority']) ? $data['sort_priority'] : 100));
            $model->setEnabled(!empty($data['enabled']) ? 1 : 0);

            $model->save();

            // Persist the category filter in the mibizum_sync_attribute_badge_categories
            // bridge. First clear all of the badge's assignments, then re-insert
            // those from the POST. This supports both adding and removing categories.
            try {
                $resource = Mage::getSingleton('core/resource');
                $write = $resource->getConnection('core_write');
                $bridgeTable = $resource->getTableName('mibizum_sync/attributeBadgeCategory');

                $write->delete($bridgeTable, $write->quoteInto('badge_id = ?', (int) $model->getId()));

                if (!empty($data['attribute_categories_json'])) {
                    $cats = json_decode($data['attribute_categories_json'], true);
                    if (is_array($cats)) {
                        foreach ($cats as $c) {
                            if (!isset($c['category_id']) || (int) $c['category_id'] <= 0) {
                                continue;
                            }
                            $write->insertOnDuplicate($bridgeTable, array(
                                'badge_id'             => (int) $model->getId(),
                                'category_id'          => (int) $c['category_id'],
                                'include_descendants'  => !empty($c['include_descendants']) ? 1 : 0,
                            ));
                        }
                    }
                }
            } catch (Exception $e) {
                Mage::log('AttributeBadge save category bridge failed: ' . $e->getMessage(), Zend_Log::WARN, 'mibizum_sync.log');
            }

            Mage::getSingleton('adminhtml/session')->addSuccess(
                $this->__('Badge for "%s" saved.', htmlspecialchars($code))
            );

            // The change affects ALL products (any with a value for this
            // attribute_code will show the badge). Enqueue a soft full reindex:
            // mark visible products so the cron processes them.
            try {
                /** @var Mibizum_Sync_Helper_Data $h */
                $h = Mage::helper('mibizum_sync');
                $h->log('AttributeBadge saved (id=' . $model->getId() . ' code=' . $code . ') - full reindex recommended', Zend_Log::INFO);
                Mage::getSingleton('adminhtml/session')->addNotice(
                    $this->__('Remember to run a full reindex from MIBIZUM Labs > Reindex so products show the new badge.')
                );
            } catch (Exception $e) {}

            if ($this->getRequest()->getParam('back')) {
                return $this->_redirect('*/*/edit', array('id' => $model->getId()));
            }
            return $this->_redirect('adminhtml/system_config/edit', array('section' => 'mibizum_sync_badges'));
        } catch (Exception $e) {
            Mage::getSingleton('adminhtml/session')->addError($e->getMessage());
            Mage::getSingleton('adminhtml/session')->setFormData($data);
            return $this->_redirect('*/*/edit', array('id' => $id));
        }
    }

    public function deleteAction()
    {
        if (!$this->_guardWrite()) {
            return;
        }
        $id = (int) $this->getRequest()->getParam('id');
        if ($id) {
            try {
                /** @var Mibizum_Sync_Model_AttributeBadge $model */
                $model = Mage::getModel('mibizum_sync/attributeBadge')->load($id);
                if ($model->getId()) {
                    $model->delete();
                    Mage::getSingleton('adminhtml/session')->addSuccess($this->__('Badge deleted.'));
                    Mage::getSingleton('adminhtml/session')->addNotice(
                        $this->__('Remember to run a reindex to remove the badge from the indexed hits.')
                    );
                }
            } catch (Exception $e) {
                Mage::getSingleton('adminhtml/session')->addError($e->getMessage());
            }
        }
        $this->_redirect('adminhtml/system_config/edit', array('section' => 'mibizum_sync_badges'));
    }

    public function massDeleteAction()
    {
        if (!$this->_guardWrite()) {
            return;
        }
        $ids = $this->getRequest()->getParam('badge_ids');
        if (!is_array($ids) || empty($ids)) {
            Mage::getSingleton('adminhtml/session')->addError($this->__('Select at least one badge.'));
        } else {
            try {
                foreach ($ids as $id) {
                    Mage::getModel('mibizum_sync/attributeBadge')->load((int) $id)->delete();
                }
                Mage::getSingleton('adminhtml/session')->addSuccess(
                    $this->__('%d badges deleted.', count($ids))
                );
            } catch (Exception $e) {
                Mage::getSingleton('adminhtml/session')->addError($e->getMessage());
            }
        }
        $this->_redirect('adminhtml/system_config/edit', array('section' => 'mibizum_sync_badges'));
    }

    public function massEnableAction()  { $this->_massSetEnabled(1); }
    public function massDisableAction() { $this->_massSetEnabled(0); }

    protected function _massSetEnabled($value)
    {
        if (!$this->_guardWrite()) {
            return;
        }
        $ids = $this->getRequest()->getParam('badge_ids');
        if (!is_array($ids) || empty($ids)) {
            Mage::getSingleton('adminhtml/session')->addError($this->__('Select at least one badge.'));
        } else {
            try {
                foreach ($ids as $id) {
                    Mage::getModel('mibizum_sync/attributeBadge')->load((int) $id)
                        ->setEnabled((int) $value)->save();
                }
                Mage::getSingleton('adminhtml/session')->addSuccess(
                    $this->__('%d badges updated.', count($ids))
                );
            } catch (Exception $e) {
                Mage::getSingleton('adminhtml/session')->addError($e->getMessage());
            }
        }
        $this->_redirect('adminhtml/system_config/edit', array('section' => 'mibizum_sync_badges'));
    }

    /**
     * AJAX: list of available attribute_codes (eav catalog_product).
     * Returns [{code, frontend_label, frontend_input}, ...] filterable by substring.
     * GET /mcpanel/mibizum_sync_attributebadge/searchAttributes/?q=country
     */
    public function searchAttributesAction()
    {
        $q = trim((string) $this->getRequest()->getParam('q'));
        /** @var Mibizum_Sync_Helper_Data $h */
        $h = Mage::helper('mibizum_sync');

        try {
            /** @var Mage_Eav_Model_Resource_Entity_Attribute_Collection $coll */
            $coll = Mage::getResourceModel('catalog/product_attribute_collection')
                ->addFieldToSelect('attribute_code')
                ->addFieldToSelect('frontend_label')
                ->addFieldToSelect('frontend_input')
                ->addFieldToSelect('attribute_id')
                ->setOrder('attribute_code', 'ASC');

            if ($q !== '') {
                // OR between attribute_code LIKE and frontend_label LIKE.
                // addFieldToFilter with OR syntax does not work on eav
                // collections (it interprets the array as a single odd field
                // and blows up with "Unknown column 'attribute_code.%q%'").
                // Use getSelect()->where with explicit quoteInto.
                $like = '%' . $q . '%';
                $conn = $coll->getConnection();
                $coll->getSelect()->where(
                    $conn->quoteInto('main_table.attribute_code LIKE ?', $like)
                    . ' OR ' .
                    $conn->quoteInto('main_table.frontend_label LIKE ?', $like)
                );
            }
            $coll->setPageSize(50);

            $out = array();
            foreach ($coll as $a) {
                $out[] = array(
                    'code'           => (string) $a->getAttributeCode(),
                    'frontend_label' => (string) $a->getFrontendLabel(),
                    'frontend_input' => (string) $a->getFrontendInput(),
                );
            }

            $h->log('searchAttributes q="' . $q . '" returning ' . count($out), Zend_Log::DEBUG);

            $this->getResponse()
                ->setHeader('Content-Type', 'application/json; charset=UTF-8', true)
                ->setBody(json_encode($out, JSON_UNESCAPED_UNICODE));
        } catch (Exception $e) {
            $h->log('searchAttributes failed: ' . $e->getMessage(), Zend_Log::ERR);
            $this->getResponse()
                ->setHttpResponseCode(500)
                ->setHeader('Content-Type', 'application/json; charset=UTF-8', true)
                ->setBody(json_encode(array('error' => $e->getMessage())));
        }
    }

    /**
     * AJAX: icon upload. Same destination as NatureController.
     */
    public function uploadIconAction()
    {
        /** @var Mibizum_Sync_Helper_Data $h */
        $h = Mage::helper('mibizum_sync');
        $this->getResponse()->setHeader('Content-Type', 'application/json; charset=UTF-8', true);

        // CSRF: write action. Endpoint JSON, no redirigir (a diferencia de
        // _guardWrite); exige POST + form_key valido y devuelve error JSON.
        if (!$this->getRequest()->isPost() || !$this->_validateFormKey()) {
            $this->getResponse()
                ->setHttpResponseCode(403)
                ->setBody(json_encode(array(
                    'ok'    => false,
                    'error' => $this->__('Invalid or missing security token. Please reload and retry.'),
                ), JSON_UNESCAPED_UNICODE));
            return;
        }

        try {
            if (empty($_FILES['icon_file']) || !is_array($_FILES['icon_file'])) {
                throw new Exception($this->__('No file received.'));
            }
            $maxBytes = 512 * 1024;
            $size = isset($_FILES['icon_file']['size']) ? (int) $_FILES['icon_file']['size'] : 0;
            if ($size <= 0) throw new Exception($this->__('Empty file or failed upload.'));
            if ($size > $maxBytes) throw new Exception($this->__('The file is too large (500 KB max).'));

            $uploader = new Varien_File_Uploader('icon_file');
            $uploader->setAllowedExtensions(array('png', 'jpg', 'jpeg', 'svg', 'webp'));
            $uploader->setAllowRenameFiles(true);
            $uploader->setFilesDispersion(false);

            $mediaPath = Mage::getBaseDir('media') . DS . 'mibizum_sync_badges';
            if (!is_dir($mediaPath)) mkdir($mediaPath, 0775, true);

            $result = $uploader->save($mediaPath);
            if (empty($result['file'])) throw new Exception($this->__('Could not save the file.'));

            $savedExt = strtolower(pathinfo($result['file'], PATHINFO_EXTENSION));
            $savedAbs = rtrim($mediaPath, '/' . DS) . DS . ltrim($result['file'], '/' . DS);
            Mage::helper('mibizum_sync/image')->sanitizeUploadedIcon($savedAbs, $savedExt);

            $relPath = ltrim(str_replace(DS, '/', $result['file']), '/');
            $url = rtrim(Mage::getBaseUrl('media'), '/') . '/mibizum_sync_badges/' . $relPath;

            $this->getResponse()->setBody(json_encode(array('ok' => true, 'url' => $url, 'name' => $result['file']), JSON_UNESCAPED_SLASHES));
        } catch (Exception $e) {
            $h->log('uploadIcon (attr) failed: ' . $e->getMessage(), Zend_Log::ERR);
            $this->getResponse()
                ->setHttpResponseCode(400)
                ->setBody(json_encode(array('ok' => false, 'error' => $e->getMessage()), JSON_UNESCAPED_UNICODE));
        }
    }
}
