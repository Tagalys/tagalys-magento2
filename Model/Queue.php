<?php

namespace Tagalys\Sync\Model;

use Tagalys\Sync\Api\Data\QueueInterface;

class Queue  extends \Magento\Framework\Model\AbstractModel implements QueueInterface
{

    /**
     * CMS page cache tag
     */
    const CACHE_TAG = 'tagalys_queue';

    /**
     * @var string
     */
    protected $_cacheTag = 'tagalys_queue';

    /**
     * Prefix of model events names
     *
     * @var string
     */
    protected $_eventPrefix = 'tagalys_queue';

    /**
     * Initialize resource model
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('Tagalys\Sync\Model\ResourceModel\Queue');
    }

    public function doesProductIdExist($productId)
    {
        return $this->_getResource()->doesProductIdExist($productId);
    }

    public function getId()
    {
        return $this->getData(self::ID);
    }

    public function getProductId()
    {
        return $this->getData(self::PRODUCT_ID);
    }


    /**
     * Set ID
     *
     * @param int $id
     * @return \Tagalys\Sync\Api\Data\ConfigInterface
     */
    public function setId($id)
    {
        return $this->setData(self::ID, $id);
    }

    public function setProductId($productId)
    {
        return $this->setData(self::PRODUCT_ID, $productId);
    }
}