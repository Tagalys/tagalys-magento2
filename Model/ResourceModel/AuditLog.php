<?php
namespace Tagalys\Sync\Model\ResourceModel;

class AuditLog extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    public function __construct(
        \Magento\Framework\Model\ResourceModel\Db\Context $context
    )
    {
        parent::__construct($context);
    }

    protected function _construct()
    {
        $this->_init('tagalys_audit_log', 'entity_id');
    }
}
