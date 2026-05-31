<?php
/**
 * Mibizum_Sync_Adminhtml_Mibizum_Sync_WizardController
 *
 * Native write-back endpoints for the first-run install wizard. The wizard UI
 * is an overlay injected on every admin page (Block_Adminhtml_Wizard); the
 * account/login/key-provisioning/Widget-Studio steps run inside a hosted
 * iframe (app.mibizum.io). The iframe hands the issued keys back to the parent
 * via window.postMessage; the parent POSTs them here, where they are stored
 * ENCRYPTED in Magento config. The indexing + widget steps are native.
 *
 * URL: /<admin>/mibizum_sync_wizard/<action>/
 * (2 underscores in the route => 3 path levels under controllers/Adminhtml/.)
 *
 * Every write action is ACL-gated AND form_key validated. The indexer key is a
 * SECRET: it is encrypted before persisting and never logged.
 *
 * Compatible with PHP 5.4+.
 */
class Mibizum_Sync_Adminhtml_Mibizum_Sync_WizardController extends Mage_Adminhtml_Controller_Action
{
    /** Wizard states the UI is allowed to persist via stateAction. */
    protected $_allowedStates = array(
        'pending', 'intro', 'account', 'provisioning',
        'indexing', 'widget', 'studio', 'done', 'dismissed',
    );

    protected function _isAllowed()
    {
        return Mage::getSingleton('admin/session')
            ->isAllowed('admin/system/config/mibizum_sync');
    }

    /** @return Mibizum_Sync_Helper_Data */
    protected function _helper()
    {
        return Mage::helper('mibizum_sync');
    }

    /**
     * Emit a JSON response and stop. Used by every action (the wizard is
     * AJAX-only; there is no full-page fallback).
     *
     * @param bool   $success
     * @param string $message
     * @param array  $extra
     */
    protected function _json($success, $message = '', array $extra = array())
    {
        $payload = array_merge(array(
            'success' => (bool) $success,
            'message' => (string) $message,
        ), $extra);
        $this->getResponse()
            ->setHeader('Content-Type', 'application/json; charset=UTF-8', true)
            ->setBody(json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Guard: POST + valid form_key. Returns false (and emits the JSON error)
     * when the request must be rejected.
     *
     * @return bool
     */
    protected function _guardWrite()
    {
        if (!$this->getRequest()->isPost()) {
            $this->_json(false, $this->__('POST required.'));
            return false;
        }
        if (!$this->_validateFormKey()) {
            $this->_json(false, $this->__('Invalid or missing form key.'));
            return false;
        }
        return true;
    }

    /**
     * Step 3 write-back: store the keys issued by the SaaS (encrypted) and turn
     * the connection on. Body: indexer_key, search_key, data_source_slug.
     */
    public function saveKeysAction()
    {
        if (!$this->_guardWrite()) {
            return;
        }
        $indexer = trim((string) $this->getRequest()->getPost('indexer_key', ''));
        $search  = trim((string) $this->getRequest()->getPost('search_key', ''));
        $slug    = trim((string) $this->getRequest()->getPost('data_source_slug', ''));

        if ($indexer === '' && $search === '') {
            $this->_json(false, $this->__('No keys received from Mibizum.'));
            return;
        }

        try {
            $cfg = Mage::getConfig();
            $enc = Mage::helper('core');

            if ($indexer !== '') {
                $cfg->saveConfig(Mibizum_Sync_Helper_Data::XML_PATH_API_KEY, $enc->encrypt($indexer), 'default', 0);
            }
            if ($search !== '') {
                $cfg->saveConfig(Mibizum_Sync_Helper_Data::XML_PATH_SEARCH_API_KEY, $enc->encrypt($search), 'default', 0);
            }
            $cfg->saveConfig(Mibizum_Sync_Helper_Data::XML_PATH_DATA_SOURCE_SLUG, $slug, 'default', 0);
            // Turn the connection on (indexing + Enter override) once keys exist.
            $cfg->saveConfig(Mibizum_Sync_Helper_Data::XML_PATH_ENABLED, '1', 'default', 0);

            $this->_helper()->setWizardState('indexing');   // also flushes config cache
            // Never log key values.
            $this->_helper()->log('wizard: connection keys stored, state -> indexing', Zend_Log::INFO);

            $this->_json(true, $this->__('Connection saved.'), array('state' => 'indexing'));
        } catch (Exception $e) {
            $this->_helper()->log('wizard saveKeys failed: ' . $e->getMessage(), Zend_Log::ERR);
            $this->_json(false, $e->getMessage());
        }
    }

    /**
     * Step 5 write-back: store the widget snippet and enable the storefront
     * widget. Body: widget_snippet. Empty snippet is allowed (the merchant can
     * add it later from the panel); we still enable the general switch.
     */
    public function widgetAction()
    {
        if (!$this->_guardWrite()) {
            return;
        }
        $snippet = (string) $this->getRequest()->getPost('widget_snippet', '');
        try {
            $cfg = Mage::getConfig();
            $cfg->saveConfig(Mibizum_Sync_Helper_Data::XML_PATH_WIDGET_SNIPPET, $snippet, 'default', 0);
            $cfg->saveConfig(Mibizum_Sync_Helper_Data::XML_PATH_GENERAL_ENABLED, '1', 'default', 0);
            $this->_helper()->setWizardState('studio');
            $this->_json(true, $this->__('Widget enabled.'), array('state' => 'studio'));
        } catch (Exception $e) {
            $this->_helper()->log('wizard widget save failed: ' . $e->getMessage(), Zend_Log::ERR);
            $this->_json(false, $e->getMessage());
        }
    }

    /** Persist an arbitrary (whitelisted) wizard state. Body: state. */
    public function stateAction()
    {
        if (!$this->_guardWrite()) {
            return;
        }
        $state = trim((string) $this->getRequest()->getPost('state', ''));
        if (!in_array($state, $this->_allowedStates, true)) {
            $this->_json(false, $this->__('Unknown wizard state.'));
            return;
        }
        try {
            $this->_helper()->setWizardState($state);
            $this->_json(true, '', array('state' => $state));
        } catch (Exception $e) {
            $this->_json(false, $e->getMessage());
        }
    }

    /** Dismiss the wizard (resume banner stays, overlay does not reopen). */
    public function dismissAction()
    {
        if (!$this->_guardWrite()) {
            return;
        }
        try {
            $this->_helper()->setWizardState('dismissed');
            $this->_json(true, '', array('state' => 'dismissed'));
        } catch (Exception $e) {
            $this->_json(false, $e->getMessage());
        }
    }
}
