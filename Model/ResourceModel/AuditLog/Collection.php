<?php
namespace Tagalys\Sync\Model\ResourceModel\AuditLog;

class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    protected $_idFieldName = 'entity_id';
    protected $_eventPrefix = 'tagalys_sync_audit_log_collection';
    protected $_eventObject = 'audit_log_collection';

    /**
     * Define the resource model & the model.
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('Tagalys\Sync\Model\AuditLog', 'Tagalys\Sync\Model\ResourceModel\AuditLog');
    }
}
