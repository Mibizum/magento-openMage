<?php
/**
 * Mibizum_Sync_Controller_SmartItemRouter
 *
 * Clean-URL router for the Smart Item (ingredient) ficha:
 *   /{url_prefix}/{slug}  -> Mibizum_Sync_IndexController::viewAction (slug param)
 *   /{url_prefix}/        -> Mibizum_Sync_IndexController::indexAction
 *
 * Registered via the controller_front_init_routers event (see
 * Mibizum_Sync_Model_Observer::initControllerRouters). Because match() runs on
 * EVERY frontend request, it is fully defensive: it returns false fast for any
 * URL that is not the ingredient prefix and never throws (a fatal here would
 * take down every storefront page).
 *
 * Thin port (Option B): the Mibizum panel is the source of truth; this module
 * only renders the public ficha. Folded into Mibizum_Sync so there is no
 * separate Mibizum_Ingredient module.
 *
 * Compatible with PHP 5.4+.
 */
class Mibizum_Sync_Controller_SmartItemRouter extends Mage_Core_Controller_Varien_Router_Abstract
{
    public function match(Zend_Controller_Request_Http $request)
    {
        try {
            /** @var Mibizum_Sync_Helper_SmartItem $helper */
            $helper = Mage::helper('mibizum_sync/smartItem');
            if (!$helper->isFichaEnabled()) {
                return false;
            }

            $prefix = $helper->getUrlPrefix();
            if ($prefix === '') {
                return false;
            }

            $pathInfo = trim($request->getPathInfo(), '/');
            if ($pathInfo !== $prefix && strpos($pathInfo, $prefix . '/') !== 0) {
                return false;
            }

            // Strip the prefix; the remainder is the slug (or empty for index).
            $remainder = trim(substr($pathInfo, strlen($prefix)), '/');

            $request->setModuleName($prefix)        // frontName='ingredientes'
                    ->setControllerName('index')
                    ->setActionName($remainder === '' ? 'index' : 'view');

            if ($remainder !== '') {
                $request->setParam('slug', $remainder);
            }

            $request->setAlias(
                Mage_Core_Model_Url_Rewrite::REWRITE_REQUEST_PATH_ALIAS,
                $pathInfo
            );
            return true;
        } catch (Exception $e) {
            // Never break the storefront because of the ingredient router.
            return false;
        }
    }
}
