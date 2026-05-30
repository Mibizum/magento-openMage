<?php
/**
 * Inline render of the Indexable attributes grid inside system_config.
 *
 * Compatible with PHP 5.4+.
 */
class Mibizum_Sync_Block_Adminhtml_Inline_Attributes
    extends Mibizum_Sync_Block_Adminhtml_Inline_Container
{
    protected function _getInnerBlockAlias()
    {
        return 'mibizum_sync/adminhtml_attribute';
    }
}
