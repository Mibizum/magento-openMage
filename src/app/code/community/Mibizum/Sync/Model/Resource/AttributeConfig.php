<?php
/**
 * Mibizum_Sync_Model_Resource_AttributeConfig
 *
 * Resource model for mibizum_sync_attribute_config. Handles persistence.
 *
 * Compatible with PHP 5.4 through 8.x.
 */
class Mibizum_Sync_Model_Resource_AttributeConfig extends Mage_Core_Model_Resource_Db_Abstract
{
    protected function _construct()
    {
        $this->_init('mibizum_sync/attributeConfig', 'config_id');
    }
}
