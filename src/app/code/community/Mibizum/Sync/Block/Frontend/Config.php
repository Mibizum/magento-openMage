<?php
/**
 * Mibizum_Sync_Block_Frontend_Config
 *
 * Injects into the storefront <head> the widget SNIPPET that the merchant
 * pastes from the Mibizum panel (Domains > JS Code), VERBATIM.
 *
 * The module does NOT build the <script> itself (api key + slug + selector ->
 * /sdk/v1.js). It only emits whatever the merchant pasted in
 * `mibizum_sync/frontend/widget_snippet`. If it is empty, it injects nothing.
 *
 * This decouples the module from the snippet structure: the panel can emit an
 * evergreen loader (/sdk/v1.js, auto-updates within the 1.x major) or a pinned
 * version (/sdk/v1.x.y.js) without needing to update the module. Same pattern
 * as Magento's native "Miscellaneous Scripts" (design/head/includes).
 *
 * Strict pattern: the snippet the panel generates always loads the SDK async;
 * this block does no network access at render time.
 *
 * Compatible with PHP 5.4+.
 */
class Mibizum_Sync_Block_Frontend_Config extends Mage_Core_Block_Template
{
    /**
     * JS snippet pasted by the merchant. '' if nothing is configured, in which
     * case the template emits nothing.
     *
     * @return string
     */
    public function getWidgetSnippet()
    {
        return Mage::helper('mibizum_sync')->getWidgetSnippet();
    }
}
