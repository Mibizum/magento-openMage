<?php
/**
 * Inline render inside System > Configuration for the 5 MIBIZUM shortcut
 * sections (Attributes, Category Badges, Attribute Badges, System Badges,
 * Reindex). Each section has a unique group whose <frontend_model> points to
 * a subclass of this Container, which instantiates the real grid block and
 * returns its HTML wrapped in a minimal config fieldset.
 *
 * Implements the Varien_Data_Form_Element renderer contract. It intentionally
 * does NOT extend Mage_Adminhtml_Block_System_Config_Form_Fieldset (previous
 * attempts caused 500 errors plus a silent class_exists failure on CLI). It
 * renders directly from Mage_Adminhtml_Block_Abstract.
 *
 * Subclasses only implement _getInnerBlockAlias(), returning the alias of the
 * existing block (mibizum_sync/adminhtml_attribute, etc).
 *
 * Compatible with PHP 5.4+.
 */
abstract class Mibizum_Sync_Block_Adminhtml_Inline_Container
    extends Mage_Adminhtml_Block_Abstract
    implements Varien_Data_Form_Element_Renderer_Interface
{
    /**
     * @return string Magento alias of the block that renders the content.
     */
    abstract protected function _getInnerBlockAlias();

    /**
     * Optional data (e.g. absolute URLs) that the child block needs.
     * Subclasses override this if their block requires setData('xxx', ...).
     *
     * @return array<string,mixed>
     */
    protected function _getInnerBlockData()
    {
        return array();
    }

    public function render(Varien_Data_Form_Element_Abstract $element)
    {
        $this->setElement($element);

        $alias = $this->_getInnerBlockAlias();
        $inner = $this->getLayout()->createBlock($alias);

        if (!$inner) {
            return $this->_wrap(
                $element,
                '<div class="messages"><ul class="messages"><li class="error-msg"><ul><li>'
                . $this->__('Could not instantiate block %s.', $alias)
                . '</li></ul></li></ul></div>'
            );
        }

        foreach ($this->_getInnerBlockData() as $key => $value) {
            $inner->setData($key, $value);
        }

        try {
            $html = $inner->toHtml();
        } catch (Exception $e) {
            Mage::logException($e);
            $html = '<div class="messages"><ul class="messages"><li class="error-msg"><ul><li>'
                . $this->htmlEscape($e->getMessage())
                . '</li></ul></li></ul></div>';
        }

        return $this->_wrap($element, $html);
    }

    /**
     * Wraps the child block's HTML in the chrome expected by system_config:
     * an entry-edit div with fieldset + legend, replicating the look of the
     * other fieldsets but without the inputs and without the expandable toggle
     * (the grid is always visible).
     *
     * @param Varien_Data_Form_Element_Abstract $element
     * @param string $innerHtml
     * @return string
     */
    protected function _wrap(Varien_Data_Form_Element_Abstract $element, $innerHtml)
    {
        $legend = $this->htmlEscape((string) $element->getLegend());
        $id     = $this->htmlEscape($element->getHtmlId());

        // Structure identical to the one Magento uses for the system_config
        // fieldsets: header as an <a> with onclick Fieldset.toggleCollapse, a
        // config_state[<id>] state input, and the fieldset with id <id>.
        //
        // Collapsed/expanded state: read from the admin user's extra data
        // (where Magento persists it via AJAX on click). If nothing is set, it
        // opens by default.
        $stateUrl = $this->getUrl('*/system_config/state');
        $state    = $this->_loadCollapseState($element->getHtmlId());

        $anchorClass   = $state ? 'open' : 'closed';
        $fieldsetStyle = $state ? '' : ' style="display:none;"';

        $html  = '<div class="entry-edit section-config active" id="' . $id . '-section">';
        $html .= '<div class="entry-edit-head collapseable">';
        $html .= '<a id="' . $id . '-head" href="#" class="' . $anchorClass . '"'
              .  ' onclick="Fieldset.toggleCollapse(\'' . $id . '\', \'' . $stateUrl . '\'); return false;">'
              .  $legend
              .  '</a>';
        $html .= '</div>';
        $html .= '<input id="' . $id . '-state" name="config_state[' . $id
              . ']" type="hidden" value="' . (int) $state . '" />';
        $html .= '<fieldset class="config" id="' . $id . '"' . $fieldsetStyle . '>';
        $html .= '<legend>' . $legend . '</legend>';
        $html .= '<div class="mibizum-search-inline-grid" style="padding:8px 0;">' . $innerHtml . '</div>';
        $html .= '</fieldset>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Reads the saved open/closed state for this fieldset.
     * Magento persists the state in admin_user.extra[configState][<id>] when
     * the user clicks; `Mage_Adminhtml_System_ConfigController::stateAction` is
     * the AJAX endpoint that writes it.
     *
     * @param  string $htmlId
     * @return int 1 open, 0 closed (default: 1).
     */
    protected function _loadCollapseState($htmlId)
    {
        try {
            $user = Mage::getSingleton('admin/session')->getUser();
            if (!$user) return 1;
            $extra = $user->getExtra();
            if (is_array($extra) && isset($extra['configState'][$htmlId])) {
                return (int) $extra['configState'][$htmlId];
            }
        } catch (Exception $e) {
            // In edge contexts (CLI, scripts) there may be no admin session.
        }
        return 1;
    }
}
