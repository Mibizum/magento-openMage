<?php
/**
 * First-run install wizard overlay.
 *
 * Injected on EVERY admin screen via the adminhtml <default> layout (the
 * `notifications` reference, like QueueNotice). It renders:
 *   - the full overlay   while the wizard is active and the store is NOT yet
 *                        connected (states: pending..studio);
 *   - a resume banner    once the merchant chose "Later" (state=dismissed);
 *   - nothing            when the store is already connected (manual setup) or
 *                        the wizard is done.
 *
 * Architecture: the account / key-provisioning / Widget-Studio steps live in a
 * hosted iframe (app.mibizum.io/onboarding/magento). The iframe posts the
 * issued keys back; the parent JS (in the template) forwards them to the native
 * WizardController, which stores them encrypted. Indexing + widget steps are
 * native (reuse the existing Reindex endpoints). If the SaaS/iframe is
 * unreachable, the overlay offers a "configure manually" link and never blocks
 * the admin.
 *
 * Compatible with PHP 5.4+.
 */
class Mibizum_Sync_Block_Adminhtml_Wizard extends Mage_Adminhtml_Block_Template
{
    const MODE_OVERLAY = 'overlay';
    const MODE_BANNER  = 'banner';
    const MODE_NONE    = '';

    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('mibizum_sync/wizard.phtml');
    }

    /** @return Mibizum_Sync_Helper_Data */
    protected function _helper()
    {
        return Mage::helper('mibizum_sync');
    }

    /**
     * Decide what (if anything) to show. Kept tolerant: any failure resolves to
     * "show nothing" so the wizard can never break the admin.
     *
     * @return string self::MODE_*
     */
    public function getMode()
    {
        try {
            if (!Mage::getSingleton('admin/session')->isAllowed('admin/system/config/mibizum_sync')) {
                return self::MODE_NONE;
            }
            // Already connected (default scope or any store-view) => manual setup,
            // the wizard must never trigger.
            if ($this->_helper()->isEnabledAnywhere()) {
                return self::MODE_NONE;
            }
            $state = $this->_helper()->getWizardState();
            if ($state === Mibizum_Sync_Helper_Data::WIZARD_DONE) {
                return self::MODE_NONE;
            }
            if ($state === Mibizum_Sync_Helper_Data::WIZARD_DISMISSED) {
                return self::MODE_BANNER;
            }
            return self::MODE_OVERLAY;
        } catch (Exception $e) {
            return self::MODE_NONE;
        }
    }

    public function getState()
    {
        return $this->_helper()->getWizardState();
    }

    /** The store domain we are about to connect (shown in the intro step). */
    public function getStoreDomain()
    {
        $host = @parse_url(Mage::getBaseUrl(), PHP_URL_HOST);
        return $host ? (string) $host : '';
    }

    /** Origin of the current admin page (passed to the iframe; postMessage target). */
    public function getAdminOrigin()
    {
        $req = $this->getRequest();
        $host = $req->getHttpHost();
        if (!$host) {
            return '';
        }
        return ($req->isSecure() ? 'https' : 'http') . '://' . $host;
    }

    /** Exact origin the parent JS will accept postMessage from. */
    public function getMibizumOrigin()
    {
        return $this->_helper()->getMibizumOrigin();
    }

    /** Hosted onboarding page URL (account / keys / Widget Studio). */
    public function getOnboardingUrl()
    {
        $base = rtrim($this->_helper()->getApiUrl(), '/') . '/onboarding/magento';
        $params = array(
            'origin' => $this->getAdminOrigin(),
            'domain' => $this->getStoreDomain(),
            'lang'   => substr((string) Mage::app()->getLocale()->getLocaleCode(), 0, 2),
        );
        return $base . '?' . http_build_query($params);
    }

    public function getSaveKeysUrl()  { return $this->getUrl('adminhtml/mibizum_sync_wizard/saveKeys'); }
    public function getWidgetUrl()    { return $this->getUrl('adminhtml/mibizum_sync_wizard/widget'); }
    public function getStateUrl()     { return $this->getUrl('adminhtml/mibizum_sync_wizard/state'); }
    public function getDismissUrl()   { return $this->getUrl('adminhtml/mibizum_sync_wizard/dismiss'); }
    public function getFullReindexUrl(){ return $this->getUrl('adminhtml/mibizum_sync_reindex/full'); }
    public function getStatsUrl()     { return $this->getUrl('adminhtml/mibizum_sync_reindex/stats'); }

    /** Manual fallback: jump to System Config -> Mibizum Sync. */
    public function getConfigUrl()
    {
        return $this->getUrl('adminhtml/system_config/edit', array('section' => 'mibizum_sync'));
    }

    public function getFormKey()
    {
        return Mage::getSingleton('core/session')->getFormKey();
    }

    protected function _toHtml()
    {
        if ($this->getMode() === self::MODE_NONE) {
            return '';
        }
        return parent::_toHtml();
    }
}
