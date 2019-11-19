<?php namespace Tagalys\Sync\Model\ResourceModel\Category;

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
    $this->_init('Tagalys\Sync\Model\Category', 'Tagalys\Sync\Model\ResourceModel\Category');
  }
}
