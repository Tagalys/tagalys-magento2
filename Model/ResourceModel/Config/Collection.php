<?php namespace Tagalys\Sync\Model\ResourceModel\Config;

class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = 'id';

    /**
     * Define resource model
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('Tagalys\Sync\Model\Config', 'Tagalys\Sync\Model\ResourceModel\Config');
    }

}