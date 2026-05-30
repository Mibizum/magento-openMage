<?php
/**
 * Inline render of the Reindex console inside system_config.
 *
 * Injects absolute URLs into the child block, since inside system_config the
 * relative `* / * /<action>` would resolve against system_config/edit/full and
 * break the actions. The child block (Adminhtml_Reindex) already supports
 * setData('full_reindex_url', ...) to override them.
 *
 * Compatible with PHP 5.4+.
 */
class Mibizum_Sync_Block_Adminhtml_Inline_Reindex
    extends Mibizum_Sync_Block_Adminhtml_Inline_Container
{
    protected function _getInnerBlockAlias()
    {
        return 'mibizum_sync/adminhtml_reindex';
    }

    protected function _getInnerBlockData()
    {
        $u = Mage::helper('adminhtml');
        return array(
            'full_reindex_url' => $u->getUrl('adminhtml/mibizum_sync_reindex/full'),
            'drain_queue_url'  => $u->getUrl('adminhtml/mibizum_sync_reindex/drain'),
            'stats_url'        => $u->getUrl('adminhtml/mibizum_sync_reindex/stats'),
        );
    }
}
