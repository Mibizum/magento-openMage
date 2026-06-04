<?php
/**
 * Mibizum_Sync_IndexController  (frontend)
 *
 * Public Smart Item (ingredient) ficha, reached through the clean-URL router
 * (Mibizum_Sync_Controller_IngredientRouter):
 *   /{url_prefix}/{slug}  -> viewAction (renders the ficha)
 *   /{url_prefix}/        -> indexAction (placeholder)
 *
 * Data comes from GET /api/v1/smart-items/{slug} on the Mibizum SaaS, fetched
 * by Mibizum_Sync_Helper_Ingredient::fetchSmartItem(). 404 if it does not exist
 * (or the feature is disabled). Thin (Option B): the panel manages Smart Items;
 * this module only renders.
 *
 * Compatible with PHP 5.4+.
 */
class Mibizum_Sync_IndexController extends Mage_Core_Controller_Front_Action
{
    public function preDispatch()
    {
        parent::preDispatch();
        if (!Mage::helper('mibizum_sync/ingredient')->isFichaEnabled()) {
            $this->norouteAction();
            $this->setFlag('', self::FLAG_NO_DISPATCH, true);
        }
    }

    /** /{url_prefix}/{slug} -> ficha of the Smart Item. */
    public function viewAction()
    {
        $slug = (string) $this->getRequest()->getParam('slug', '');
        if ($slug === '') {
            $this->norouteAction();
            return;
        }

        $smartItem = Mage::helper('mibizum_sync/ingredient')->fetchSmartItem($slug);
        if (!$smartItem) {
            $this->norouteAction();
            return;
        }

        Mage::register('current_smart_item', $smartItem);

        $this->loadLayout();

        $headBlock = $this->getLayout()->getBlock('head');
        if ($headBlock) {
            $title = isset($smartItem['name']) ? $smartItem['name'] : ucfirst($slug);
            $headBlock->setTitle($title);
        }

        $this->renderLayout();
    }

    /** /{url_prefix}/ -> listing placeholder. */
    public function indexAction()
    {
        $this->loadLayout();
        $this->renderLayout();
    }
}
