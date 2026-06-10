<?php
/**
 * Mibizum_Sync_Adminhtml_Mibizum_Sync_NatureController
 *
 * Screen: Mibizum > Search > Nature badges.
 *
 * URL: /<admin>/mibizum_sync_nature/<action>/key/<formkey>/
 * Magento 1 admin convention: 2 underscores in the URL require 3 path levels
 * under controllers/Adminhtml/ (Mibizum/Sync/Nature).
 *
 *  - indexAction:    badge listing (grid).
 *  - newAction:      create form (alias of edit without id).
 *  - editAction:     edit form.
 *  - saveAction:     persists the form (badge + assigned categories).
 *  - deleteAction:   deletes a badge (cascade removes its categories).
 *  - massDelete/massEnable/massDisable.
 *  - searchCategoriesAction:  AJAX category autocomplete endpoint.
 *
 * Compatible with PHP 5.4 through 8.x.
 */
class Mibizum_Sync_Adminhtml_Mibizum_Sync_NatureController extends Mage_Adminhtml_Controller_Action
{
    protected function _isAllowed()
    {
        return Mage::getSingleton('admin/session')->isAllowed('mibizum_sync/search/natures');
    }

    protected function _initLayout()
    {
        $this->loadLayout()->_setActiveMenu('mibizum_sync/search/natures');
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
            ->_addContent($this->getLayout()->createBlock('mibizum_sync/adminhtml_nature'))
            ->renderLayout();
    }

    public function newAction()
    {
        $this->_forward('edit');
    }

    public function editAction()
    {
        $id = (int) $this->getRequest()->getParam('id');
        /** @var Mibizum_Sync_Model_Nature $model */
        $model = Mage::getModel('mibizum_sync/nature');

        if ($id) {
            $model->load($id);
            if (!$model->getId()) {
                Mage::getSingleton('adminhtml/session')->addError($this->__('Badge not found.'));
                return $this->_redirect('adminhtml/system_config/edit', array('section' => 'mibizum_sync_badges'));
            }
        }

        Mage::register('current_nature', $model);

        $this->_initLayout()
            ->_addContent($this->getLayout()->createBlock('mibizum_sync/adminhtml_nature_edit'))
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

        /** @var Mibizum_Sync_Helper_Data $h */
        $h = Mage::helper('mibizum_sync');
        $h->log(
            'saveAction POST keys: ' . implode(',', array_keys($data))
            . ' / categories_json="' . substr((string) (isset($data['categories_json']) ? $data['categories_json'] : '(missing)'), 0, 500) . '"',
            Zend_Log::DEBUG
        );

        $id = (int) $this->getRequest()->getParam('id');
        /** @var Mibizum_Sync_Model_Nature $model */
        $model = Mage::getModel('mibizum_sync/nature');
        if ($id) {
            $model->load($id);
            if (!$model->getId()) {
                Mage::getSingleton('adminhtml/session')->addError($this->__('Badge not found.'));
                return $this->_redirect('adminhtml/system_config/edit', array('section' => 'mibizum_sync_badges'));
            }
        }

        try {
            // -- Snapshot BEFORE applying changes - to detect whether the save
            //    changes anything that affects the index. If nothing changes we
            //    do not enqueue products nor add a notice (avoids "X enqueued"
            //    when the user opened a badge and saved without touching it).
            $oldFingerprint = $this->_natureFingerprint(
                $model->getId() ? $model : null,
                $this->_loadAssignmentsRaw($model->getId())
            );

            $model->setLabel(trim((string) (isset($data['label']) ? $data['label'] : '')));
            // Slug: if empty, _beforeSave auto-generates it. If filled in, normalize it.
            if (isset($data['slug']) && trim($data['slug']) !== '') {
                $slug = strtolower(trim($data['slug']));
                $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
                $slug = trim(preg_replace('/-+/', '-', $slug), '-');
                $model->setSlug($slug);
            }
            $img = Mage::helper('mibizum_sync/image');
            $model->setColorHex($img->sanitizeHexColor(isset($data['color_hex']) ? $data['color_hex'] : '', '#1E9C3C'));
            $model->setTextColorHex($img->sanitizeOptionalHexColor(isset($data['text_color_hex']) ? $data['text_color_hex'] : ''));
            $model->setSortPriority((int) (isset($data['sort_priority']) ? $data['sort_priority'] : 100));
            $model->setEnabled(!empty($data['enabled']) ? 1 : 0);

            $allowedDisplay = array('icon_only', 'text_only', 'icon_and_text');
            $display = isset($data['display_mode']) && in_array($data['display_mode'], $allowedDisplay, true) ? $data['display_mode'] : 'icon_and_text';
            $model->setDisplayMode($display);

            $allowedPositions = array('top_left', 'top_right', 'bottom_left', 'bottom_right', 'below_image');
            $position = isset($data['position']) && in_array($data['position'], $allowedPositions, true) ? $data['position'] : 'bottom_left';
            $model->setPosition($position);

            $allowedShapes = array('pill', 'circle', 'square_rounded');
            $shape = isset($data['shape']) && in_array($data['shape'], $allowedShapes, true) ? $data['shape'] : 'pill';
            $model->setShape($shape);

            // Icon: inline SVG (text pasted in a textarea) or an uploaded url.
            // The SVG is sanitized (strips <script>, on*, javascript:,
            // foreignObject, entities) because it is rendered inline in the
            // storefront.
            $iconSvg     = $img->sanitizeSvg(isset($data['icon_svg']) ? $data['icon_svg'] : '');
            $iconUrl     = isset($data['icon_url'])      ? trim($data['icon_url'])      : '';
            $iconFaClass = isset($data['icon_fa_class']) ? trim($data['icon_fa_class']) : '';

            // Sanitize the FA class: only allow fa-* tokens (alnum + dash). Stops
            // XSS if someone tries to inject `"><script>...` into the hidden field.
            if ($iconFaClass !== '' && !preg_match('/^fa-[a-z0-9-]+$/', $iconFaClass)) {
                $iconFaClass = '';
            }

            $model->setIconSvg($iconSvg !== '' ? $iconSvg : null);
            $model->setIconUrl($iconUrl !== '' ? $iconUrl : null);
            $model->setIconFaClass($iconFaClass !== '' ? $iconFaClass : null);

            if ($model->getLabel() === '') {
                throw new Exception($this->__('The badge name is required.'));
            }

            $model->save();

            // Sync assigned categories. categories_json = [{category_id, include_descendants}, ...]
            // Defensive: if the form field "categories_json" does not arrive via
            // $data (can happen with a namespaced field), look in raw $_POST.
            $assignmentsRaw = '[]';
            if (isset($data['categories_json']) && $data['categories_json'] !== '') {
                $assignmentsRaw = $data['categories_json'];
            } elseif (isset($_POST['categories_json']) && $_POST['categories_json'] !== '') {
                $assignmentsRaw = $_POST['categories_json'];
            }
            $assignments = json_decode($assignmentsRaw, true);
            if (!is_array($assignments)) {
                $assignments = array();
            }
            $h->log(
                'saveAction parsed ' . count($assignments) . ' assignments from raw "' . substr($assignmentsRaw, 0, 200) . '"',
                Zend_Log::DEBUG
            );

            $resource = Mage::getSingleton('core/resource');
            $writeAdapter = $resource->getConnection('core_write');
            $bridgeTable = $resource->getTableName('mibizum_sync/natureCategory');

            $writeAdapter->delete($bridgeTable, array('badge_id = ?' => $model->getId()));

            $now = Varien_Date::now();
            foreach ($assignments as $a) {
                $catId = isset($a['category_id']) ? (int) $a['category_id'] : 0;
                if ($catId <= 0) {
                    continue;
                }
                $writeAdapter->insert($bridgeTable, array(
                    'badge_id'             => (int) $model->getId(),
                    'category_id'          => $catId,
                    'include_descendants'  => !empty($a['include_descendants']) ? 1 : 0,
                    'created_at'           => $now,
                ));
            }

            Mage::getSingleton('adminhtml/session')->addSuccess(
                $this->__('Badge "%s" saved.', htmlspecialchars($model->getLabel()))
            );

            // Enqueue affected products ONLY if something changed vs the initial
            // snapshot. Without this, opening a badge and saving it untouched
            // triggered a reindex of its whole category - unnecessary.
            $newFingerprint = $this->_natureFingerprint($model, $assignments);
            $hasChanges = ($oldFingerprint !== $newFingerprint);

            if ($hasChanges) {
                try {
                    $enqueued = $h->enqueueProductsForBadge($model);
                    if ($enqueued > 0) {
                        // The persistent admin banner (mibizum_sync.queue_notice)
                        // already invites to "Apply changes to search" from any
                        // screen; here we just confirm what the save did.
                        Mage::getSingleton('adminhtml/session')->addNotice(
                            $this->__(
                                '%d products pending update in search.',
                                $enqueued
                            )
                        );
                    }
                } catch (Exception $e) {
                    $h->log('saveAction enqueue failed: ' . $e->getMessage(), Zend_Log::WARN);
                }
            } else {
                $h->log(
                    'saveAction skip enqueue: badge ' . $model->getId()
                        . ' saved with no effective change (identical fingerprint).',
                    Zend_Log::INFO
                );
            }

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
                /** @var Mibizum_Sync_Model_Nature $model */
                $model = Mage::getModel('mibizum_sync/nature')->load($id);
                if ($model->getId()) {
                    // Enqueue affected products BEFORE deleting - post-delete the
                    // bridge assignments are gone via cascade and we cannot know
                    // who is affected.
                    /** @var Mibizum_Sync_Helper_Data $h */
                    $h = Mage::helper('mibizum_sync');
                    $enqueued = $h->enqueueProductsForBadge($model);
                    $model->delete();
                    Mage::getSingleton('adminhtml/session')->addSuccess($this->__('Badge deleted.'));
                    if ($enqueued > 0) {
                        Mage::getSingleton('adminhtml/session')->addNotice(
                            $this->__(
                                '%d products pending update in search (badge deleted).',
                                $enqueued
                            )
                        );
                    }
                }
            } catch (Exception $e) {
                Mage::getSingleton('adminhtml/session')->addError($e->getMessage());
            }
        }
        $this->_redirect('adminhtml/system_config/edit', array('section' => 'mibizum_sync_badges'));
    }

    /** Mass actions from the grid. */
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
                    Mage::getModel('mibizum_sync/nature')->load((int) $id)->delete();
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
                    Mage::getModel('mibizum_sync/nature')->load((int) $id)
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
     * AJAX: category autocomplete by substring.
     * GET /<admin>/mibizum_sync_nature/searchCategories/?q=oil
     * Returns [{id, name, path}, ...] top 20, or {error: "..."} with HTTP 500.
     */
    public function searchCategoriesAction()
    {
        $q = trim((string) $this->getRequest()->getParam('q'));
        /** @var Mibizum_Sync_Helper_Data $h */
        $h = Mage::helper('mibizum_sync');
        $h->log('searchCategories called q="' . $q . '"', Zend_Log::DEBUG);

        try {
            /** @var Mage_Catalog_Model_Resource_Category_Collection $collection */
            $collection = Mage::getModel('catalog/category')->getCollection()
                ->setStore(Mage::app()->getDefaultStoreView())
                ->addAttributeToSelect('name')
                ->addFieldToFilter('level', array('gt' => 1))
                ->setPageSize(20);

            if ($q !== '') {
                $collection->addAttributeToFilter('name', array('like' => '%' . $q . '%'));
            }

            $results = array();
            foreach ($collection as $cat) {
                $results[] = array(
                    'id'   => (int) $cat->getId(),
                    'name' => (string) $cat->getName(),
                    'path' => $this->_buildCategoryPath($cat),
                );
            }

            $h->log('searchCategories returning ' . count($results) . ' results', Zend_Log::DEBUG);

            $this->getResponse()
                ->setHeader('Content-Type', 'application/json; charset=UTF-8', true)
                ->setBody(json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (Exception $e) {
            $h->log('searchCategories failed: ' . $e->getMessage(), Zend_Log::ERR);
            $this->getResponse()
                ->setHttpResponseCode(500)
                ->setHeader('Content-Type', 'application/json; charset=UTF-8', true)
                ->setBody(json_encode(array(
                    'error' => $e->getMessage(),
                ), JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * AJAX: palette DERIVED from the existing badges.
     * GET /<admin>/mibizum_sync_nature/palette/
     *
     * Returns a dropdown with each UNIQUE color used by some badge (nature or
     * system override). Each item: {color_hex, text_color_hex, used_by: [labels
     * of badges using that color]}. This helps avoid visual color collisions
     * between badges.
     *
     * Result: [{color_hex, text_color_hex, used_by: ["Hydrosols"], ...}, ...]
     */
    public function paletteAction()
    {
        try {
            $bucket = array();  // upper(hex) => {color, text, used_by[]}

            $natures = Mage::getModel('mibizum_sync/nature')->getCollection();
            foreach ($natures as $n) {
                $hex = strtoupper((string) $n->getColorHex());
                if (!preg_match('/^#[0-9A-F]{6}$/', $hex)) continue;
                if (!isset($bucket[$hex])) {
                    $bucket[$hex] = array(
                        'color_hex'      => $hex,
                        'text_color_hex' => $n->getTextColorHex() ?: '#FFFFFF',
                        'used_by'        => array(),
                    );
                }
                $bucket[$hex]['used_by'][] = (string) $n->getLabel();
            }

            $sysOverrides = Mage::getModel('mibizum_sync/systemOverride')->getCollection();
            foreach ($sysOverrides as $s) {
                $hex = strtoupper((string) $s->getColorHex());
                if (!preg_match('/^#[0-9A-F]{6}$/', $hex)) continue;
                if (!isset($bucket[$hex])) {
                    $bucket[$hex] = array(
                        'color_hex'      => $hex,
                        'text_color_hex' => $s->getTextColorHex() ?: '#FFFFFF',
                        'used_by'        => array(),
                    );
                }
                $bucket[$hex]['used_by'][] = $s->getVisibleLabel();
            }

            $out = array_values($bucket);
            $this->getResponse()
                ->setHeader('Content-Type', 'application/json; charset=UTF-8', true)
                ->setBody(json_encode($out, JSON_UNESCAPED_UNICODE));
        } catch (Exception $e) {
            $this->getResponse()
                ->setHttpResponseCode(500)
                ->setBody(json_encode(array('error' => $e->getMessage())));
        }
    }

    /**
     * AJAX helper: given a hex color, compute the appropriate text_color_hex by
     * luminosity (dark on light backgrounds, white on dark ones).
     * GET /<admin>/mibizum_sync_nature/suggestTextColor/?color_hex=#1E9C3C
     */
    public function suggestTextColorAction()
    {
        try {
            $hex = trim((string) $this->getRequest()->getParam('color_hex'));
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $hex)) {
                throw new Exception($this->__('Invalid color.'));
            }
            $textHex = Mibizum_Sync_Model_Nature::contrastingTextColor($hex);

            $this->getResponse()
                ->setHeader('Content-Type', 'application/json; charset=UTF-8', true)
                ->setBody(json_encode(array(
                    'color_hex'      => strtoupper($hex),
                    'text_color_hex' => $textHex,
                )));
        } catch (Exception $e) {
            $this->getResponse()
                ->setHttpResponseCode(400)
                ->setHeader('Content-Type', 'application/json; charset=UTF-8', true)
                ->setBody(json_encode(array('ok' => false, 'error' => $e->getMessage())));
        }
    }

    /**
     * AJAX: icon image upload (PNG/JPG/SVG/WebP).
     * POST multipart with the "icon_file" field.
     * Saves to media/mibizum_sync_badges/<random>.<ext> and returns {url, ...}.
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
            $allowedMime = array(
                'image/png', 'image/jpeg', 'image/jpg',
                'image/svg+xml', 'image/svg', 'image/webp',
                'text/plain', // svg sometimes detected as plain text
            );
            $maxBytes = 512 * 1024; // 500 KB

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

            $mime = isset($_FILES['icon_file']['type']) ? $_FILES['icon_file']['type'] : '';
            if ($mime && !in_array($mime, $allowedMime, true)) {
                $h->log('uploadIcon: non-canonical mime ' . $mime . ', continuing by extension', Zend_Log::WARN);
            }

            $mediaPath = Mage::getBaseDir('media') . DS . 'mibizum_sync_badges';
            if (!is_dir($mediaPath)) {
                mkdir($mediaPath, 0775, true);
            }

            $result = $uploader->save($mediaPath);
            if (empty($result['file'])) {
                throw new Exception($this->__('Could not save the file.'));
            }

            // Deep-clean the freshly uploaded file: raster re-encoded (drops any
            // appended payload) and SVG sanitized. Throws and deletes if it does
            // not check out.
            $savedExt = strtolower(pathinfo($result['file'], PATHINFO_EXTENSION));
            $savedAbs = rtrim($mediaPath, '/' . DS) . DS . ltrim($result['file'], '/' . DS);
            Mage::helper('mibizum_sync/image')->sanitizeUploadedIcon($savedAbs, $savedExt);

            // Build the public URL.
            $relPath = ltrim(str_replace(DS, '/', $result['file']), '/');
            $url = rtrim(Mage::getBaseUrl('media'), '/') . '/mibizum_sync_badges/' . $relPath;

            $h->log('uploadIcon OK: ' . $relPath, Zend_Log::INFO);

            $this->getResponse()->setBody(json_encode(array(
                'ok'   => true,
                'url'  => $url,
                'name' => $result['file'],
            ), JSON_UNESCAPED_SLASHES));
        } catch (Exception $e) {
            $h->log('uploadIcon failed: ' . $e->getMessage(), Zend_Log::ERR);
            $this->getResponse()
                ->setHttpResponseCode(400)
                ->setBody(json_encode(array(
                    'ok'    => false,
                    'error' => $e->getMessage(),
                ), JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * Deterministic badge fingerprint (model + assigned categories). If two
     * fingerprints match, the badge did not change in any field that affects the
     * index -> no need to enqueue a reindex.
     *
     * Only the fields that DO affect the search frontend are included: label,
     * slug, color_hex, text_color_hex, sort_priority, enabled, display_mode,
     * position, shape, icon_* and the normalized category list (id ASC +
     * include_descendants flag).
     *
     * @param Mibizum_Sync_Model_Nature|null $m
     * @param array $assignments raw [{category_id, include_descendants}, ...]
     * @return string
     */
    protected function _natureFingerprint($m, array $assignments)
    {
        if (!$m || !$m->getId()) {
            // New badge: any data counts as a change.
            return 'NEW_' . microtime(true);
        }
        $shape = (string) $m->getShape();
        $display = (string) $m->getDisplayMode();
        if ($shape === 'circle') $display = 'icon_only'; // same normalization as _beforeSave
        $parts = array(
            (string) $m->getLabel(),
            (string) $m->getSlug(),
            (string) $m->getColorHex(),
            (string) $m->getTextColorHex(),
            (int) $m->getSortPriority(),
            (int) $m->getEnabled(),
            $display,
            (string) $m->getPosition(),
            $shape,
            (string) $m->getIconSvg(),
            (string) $m->getIconUrl(),
            (string) $m->getIconFaClass(),
        );
        $cats = array();
        foreach ($assignments as $a) {
            $catId = (int) (isset($a['category_id']) ? $a['category_id'] : 0);
            if ($catId <= 0) continue;
            $cats[$catId] = !empty($a['include_descendants']) ? 1 : 0;
        }
        ksort($cats); // stable order regardless of how they arrive in the POST
        $parts[] = json_encode($cats);
        return md5(implode('|', $parts));
    }

    /**
     * Read the saved category assignments for a badge (same format as the POST:
     * [{category_id, include_descendants}]). Returns [] for a new badge.
     */
    protected function _loadAssignmentsRaw($badgeId)
    {
        $badgeId = (int) $badgeId;
        if ($badgeId <= 0) return array();
        try {
            $resource = Mage::getSingleton('core/resource');
            $read     = $resource->getConnection('core_read');
            $table    = $resource->getTableName('mibizum_sync/natureCategory');
            $rows = $read->fetchAll(
                $read->select()
                    ->from($table, array('category_id', 'include_descendants'))
                    ->where('badge_id = ?', $badgeId)
            );
            return is_array($rows) ? $rows : array();
        } catch (Exception $e) {
            return array();
        }
    }

    protected function _buildCategoryPath($cat)
    {
        // path like "1/2/4/15" -> resolve the name of each level.
        $ids = explode('/', (string) $cat->getPath());
        // Drop the root (id=1) and the shop root (level=1).
        $ids = array_slice($ids, 2);
        if (empty($ids)) {
            return (string) $cat->getName();
        }
        try {
            $names = Mage::getResourceModel('catalog/category_collection')
                ->addAttributeToSelect('name')
                ->addFieldToFilter('entity_id', array('in' => $ids))
                ->getColumnValues('name');
            return implode(' > ', $names);
        } catch (Exception $e) {
            return (string) $cat->getName();
        }
    }
}
