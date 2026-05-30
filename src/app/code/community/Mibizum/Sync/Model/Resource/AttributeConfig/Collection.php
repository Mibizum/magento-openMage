<?php
/**
 * Mibizum_Sync_Model_Resource_AttributeConfig_Collection
 *
 * Collection to list attribute configs in the admin grid. Can JOIN to
 * eav_attribute to show Magento's original frontend_label in an
 * informational column.
 *
 * Compatible with PHP 5.4 through 8.x.
 */
class Mibizum_Sync_Model_Resource_AttributeConfig_Collection
    extends Mage_Core_Model_Resource_Db_Collection_Abstract
{
    protected function _construct()
    {
        $this->_init('mibizum_sync/attributeConfig');
    }

    /**
     * Attach eav_attribute's frontend_label for display.
     *
     * @return $this
     */
    public function joinMagentoLabel()
    {
        $eav = $this->getTable('eav/attribute');
        $entityType = (int) Mage::getModel('eav/entity')
            ->setType('catalog_product')
            ->getTypeId();

        $this->getSelect()->joinLeft(
            array('e' => $eav),
            'main_table.attribute_code = e.attribute_code AND e.entity_type_id = ' . $entityType,
            array('magento_label' => 'frontend_label', 'magento_backend_type' => 'backend_type')
        );
        return $this;
    }
}
