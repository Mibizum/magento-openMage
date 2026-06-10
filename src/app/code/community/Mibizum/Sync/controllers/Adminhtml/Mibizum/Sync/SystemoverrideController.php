<?php
/**
 * Mibizum_Sync_Adminhtml_Mibizum_Sync_SystemoverrideController
 *
 * "System Badges" fieldset in General configuration.
 *
 * There are 5 fixed system badges (stock_out, stock_low, in_offer, new, featured).
 * One row per kind, seeded by upgrade-0.3.0-0.4.0. They are NOT created or deleted.
 *
 * The edit form gathers ALL of the badge's configuration: the visual aspect
 * (color, icon, position, shape) in the overrides table, and the behavior
 * (enabled, text, threshold/days) in core_config_data
 * (mibizum_sync/badges/*) - saveAction stores both halves.
 *
 * URL: /mcpanel/mibizum_sync_systemoverride/<action>/key/<formkey>/
 *
 * Compatible with PHP 5.4+.
 */
class Mibizum_Sync_Adminhtml_Mibizum_Sync_SystemoverrideController extends Mage_Adminhtml_Controller_Action
{
    protected function _isAllowed()
    {
        return Mage::getSingleton('admin/session')->isAllowed('mibizum_sync/search/system_badges');
    }

    protected function _initLayout()
    {
        $this->loadLayout()->_setActiveMenu('mibizum_sync/search/system_badges');
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
            ->_addContent($this->getLayout()->createBlock('mibizum_sync/adminhtml_systemoverride'))
            ->renderLayout();
    }

    public function editAction()
    {
        $id = (int) $this->getRequest()->getParam('id');
        if ($id <= 0) {
            Mage::getSingleton('adminhtml/session')->addError($this->__('Missing badge id.'));
            return $this->_redirect('adminhtml/system_config/edit', array('section' => 'mibizum_sync_badges'));
        }

        /** @var Mibizum_Sync_Model_SystemOverride $model */
        $model = Mage::getModel('mibizum_sync/systemOverride')->load($id);
        if (!$model->getId()) {
            Mage::getSingleton('adminhtml/session')->addError($this->__('Badge not found.'));
            return $this->_redirect('adminhtml/system_config/edit', array('section' => 'mibizum_sync_badges'));
        }
        if (!in_array($model->getKind(), Mibizum_Sync_Model_SystemOverride::$allKinds, true)) {
            Mage::getSingleton('adminhtml/session')->addError($this->__('Invalid kind.'));
            return $this->_redirect('adminhtml/system_config/edit', array('section' => 'mibizum_sync_badges'));
        }

        Mage::register('current_system_override', $model);

        $this->_initLayout()
            ->_addContent($this->getLayout()->createBlock('mibizum_sync/adminhtml_systemoverride_edit'))
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
        if ($id <= 0) {
            Mage::getSingleton('adminhtml/session')->addError($this->__('Missing id.'));
            return $this->_redirect('adminhtml/system_config/edit', array('section' => 'mibizum_sync_badges'));
        }

        /** @var Mibizum_Sync_Model_SystemOverride $model */
        $model = Mage::getModel('mibizum_sync/systemOverride')->load($id);
        if (!$model->getId()) {
            Mage::getSingleton('adminhtml/session')->addError($this->__('Badge not found.'));
            return $this->_redirect('adminhtml/system_config/edit', array('section' => 'mibizum_sync_badges'));
        }
        if (!in_array($model->getKind(), Mibizum_Sync_Model_SystemOverride::$allKinds, true)) {
            Mage::getSingleton('adminhtml/session')->addError($this->__('Invalid kind.'));
            return $this->_redirect('adminhtml/system_config/edit', array('section' => 'mibizum_sync_badges'));
        }

        try {
            $img = Mage::helper('mibizum_sync/image');
            $model->setColorHex($img->sanitizeHexColor(isset($data['color_hex']) ? $data['color_hex'] : '', '#1E9C3C'));
            $model->setTextColorHex($img->sanitizeOptionalHexColor(isset($data['text_color_hex']) ? $data['text_color_hex'] : ''));

            $allowedDisplay = array('icon_only', 'text_only', 'icon_and_text');
            $display = isset($data['display_mode']) && in_array($data['display_mode'], $allowedDisplay, true)
                ? $data['display_mode'] : 'icon_and_text';
            $model->setDisplayMode($display);

            $allowedPositions = array('top_left', 'top_right', 'bottom_left', 'bottom_right', 'below_image');
            $position = isset($data['position']) && in_array($data['position'], $allowedPositions, true)
                ? $data['position'] : 'top_left';
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

            // sort_priority: optional. Default 100. Lets the merchant order the
            // system badges among themselves (when they share the same position).
            if (isset($data['sort_priority'])) {
                $model->setSortPriority((int) $data['sort_priority']);
            }

            $model->save();

            // Badge behavior -> core_config_data (mibizum_sync/badges/*).
            // It is the other half of the badge config: the "Badges in results"
            // group in system.xml was removed and is edited right here.
            $prefix = $model->getConfigPrefix();
            $cfg    = Mage::getConfig();
            $cfg->saveConfig(
                'mibizum_sync/badges/' . $prefix . '_enabled',
                (isset($data['cfg_enabled']) && $data['cfg_enabled']) ? 1 : 0
            );
            if (isset($data['cfg_label'])) {
                $cfg->saveConfig('mibizum_sync/badges/' . $prefix . '_label', trim((string) $data['cfg_label']));
            }
            if ($model->getKind() === Mibizum_Sync_Model_SystemOverride::KIND_STOCK_LOW
                && isset($data['cfg_threshold'])) {
                $cfg->saveConfig('mibizum_sync/badges/low_stock_threshold', max(0, (int) $data['cfg_threshold']));
            }
            if ($model->getKind() === Mibizum_Sync_Model_SystemOverride::KIND_NEW
                && isset($data['cfg_days'])) {
                $cfg->saveConfig('mibizum_sync/badges/new_days', max(0, (int) $data['cfg_days']));
            }
            Mage::app()->getCacheInstance()->cleanType('config');

            $savedLabel = (isset($data['cfg_label']) && trim($data['cfg_label']) !== '')
                ? trim($data['cfg_label']) : $model->getKindLabel();
            Mage::getSingleton('adminhtml/session')->addSuccess(
                $this->__('Badge "%s" saved.', htmlspecialchars($savedLabel))
            );

            // System badges do NOT need a reindex: neither the behavior nor the
            // aspect is stored in the index document; the frontend resolves them
            // live from the config.

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

    /**
     * AJAX: icon image upload. Reuses the same logic as NatureController
     * (same destination media/mibizum_sync_badges/, same restrictions).
     * POST multipart with the "icon_file" field.
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

            $allowedExt = array('png', 'jpg', 'jpeg', 'svg', 'webp');
            $maxBytes = 512 * 1024;

            $uploader = new Varien_File_Uploader('icon_file');
            $uploader->setAllowedExtensions($allowedExt);
            $uploader->setAllowRenameFiles(true);
            $uploader->setFilesDispersion(false);

            $size = isset($_FILES['icon_file']['size']) ? (int) $_FILES['icon_file']['size'] : 0;
            if ($size <= 0) {
                throw new Exception($this->__('Empty file or failed upload.'));
            }
            if ($size > $maxBytes) {
                throw new Exception($this->__('The file is too large (500 KB max).'));
            }

            $mediaPath = Mage::getBaseDir('media') . DS . 'mibizum_sync_badges';
            if (!is_dir($mediaPath)) {
                mkdir($mediaPath, 0775, true);
            }

            $result = $uploader->save($mediaPath);
            if (empty($result['file'])) {
                throw new Exception($this->__('Could not save the file.'));
            }

            $savedExt = strtolower(pathinfo($result['file'], PATHINFO_EXTENSION));
            $savedAbs = rtrim($mediaPath, '/' . DS) . DS . ltrim($result['file'], '/' . DS);
            Mage::helper('mibizum_sync/image')->sanitizeUploadedIcon($savedAbs, $savedExt);

            $relPath = ltrim(str_replace(DS, '/', $result['file']), '/');
            $url = rtrim(Mage::getBaseUrl('media'), '/') . '/mibizum_sync_badges/' . $relPath;

            $h->log('uploadIcon (system) OK: ' . $relPath, Zend_Log::INFO);

            $this->getResponse()->setBody(json_encode(array(
                'ok'   => true,
                'url'  => $url,
                'name' => $result['file'],
            ), JSON_UNESCAPED_SLASHES));
        } catch (Exception $e) {
            $h->log('uploadIcon (system) failed: ' . $e->getMessage(), Zend_Log::ERR);
            $this->getResponse()
                ->setHttpResponseCode(400)
                ->setBody(json_encode(array(
                    'ok'    => false,
                    'error' => $e->getMessage(),
                ), JSON_UNESCAPED_UNICODE));
        }
    }

    /** Mass action: enable the selected system badges. */
    public function massEnableAction()  { $this->_massSetEnabled(true); }

    /** Mass action: disable the selected system badges. */
    public function massDisableAction() { $this->_massSetEnabled(false); }

    /**
     * Writes the "enabled" flag of the selected badges to core_config_data
     * (mibizum_sync/badges/<prefix>_enabled). No DELETE - the 5 are fixed.
     */
    protected function _massSetEnabled($enabled)
    {
        if (!$this->_guardWrite()) {
            return;
        }
        $ids = (array) $this->getRequest()->getParam('badge_ids');
        $ids = array_filter(array_map('intval', $ids));
        if (empty($ids)) {
            Mage::getSingleton('adminhtml/session')->addError($this->__('No badge was selected.'));
            return $this->_redirect('adminhtml/system_config/edit', array('section' => 'mibizum_sync_badges'));
        }

        $count = 0;
        $cfg   = Mage::getConfig();
        try {
            foreach ($ids as $id) {
                $m = Mage::getModel('mibizum_sync/systemOverride')->load($id);
                if (!$m->getId()) continue;
                $cfg->saveConfig(
                    'mibizum_sync/badges/' . $m->getConfigPrefix() . '_enabled',
                    $enabled ? 1 : 0
                );
                $count++;
            }
            Mage::app()->getCacheInstance()->cleanType('config');
            Mage::getSingleton('adminhtml/session')->addSuccess(
                $this->__('%d badge(s) %s.', $count, $enabled ? $this->__('enabled') : $this->__('disabled'))
            );
        } catch (Exception $e) {
            Mage::getSingleton('adminhtml/session')->addError($e->getMessage());
        }

        return $this->_redirect('adminhtml/system_config/edit', array('section' => 'mibizum_sync_badges'));
    }
}
