<?php
/**
 * Mibizum_Sync_Model_AttributeConfig
 *
 * Model for each row of mibizum_sync_attribute_config.
 *
 * Compatible with PHP 5.4 through 8.x.
 */
class Mibizum_Sync_Model_AttributeConfig extends Mage_Core_Model_Abstract
{
    protected function _construct()
    {
        $this->_init('mibizum_sync/attributeConfig');
    }

    /**
     * Reset the ProductMapper cache after saving.
     */
    protected function _afterSave()
    {
        Mibizum_Sync_Model_Indexer_ProductMapper::resetCache();
        return parent::_afterSave();
    }
}
