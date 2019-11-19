<?php

namespace Tagalys\Sync\Model;

use Tagalys\Sync\Api\Data\CategoryInterface;

class Category  extends \Magento\Framework\Model\AbstractModel implements CategoryInterface
{

  /**
   * CMS page cache tag
   */
  const CACHE_TAG = 'tagalys_category';

  /**
   * @var string
   */
  protected $_cacheTag = 'tagalys_category';

  /**
   * Prefix of model events names
   *
   * @var string
   */
  protected $_eventPrefix = 'tagalys_category';

  /**
   * Initialize resource model
   *
   * @return void
   */
  protected function _construct()
  {
    $this->_init('Tagalys\Sync\Model\ResourceModel\Category');
  }

  public function getId()
  {
    return $this->getData(self::ID);
  }


  /**
   * Set ID
   *
   * @param int $id
   * @return \Tagalys\Sync\Api\Data\CategoryInterface
   */
  public function setId($id)
  {
    return $this->setData(self::ID, $id);
  }
}
