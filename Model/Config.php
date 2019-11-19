<?php

namespace Tagalys\Sync\Model;

use Tagalys\Sync\Api\Data\ConfigInterface;

class Config  extends \Magento\Framework\Model\AbstractModel implements ConfigInterface
{

    /**
     * CMS page cache tag
     */
    const CACHE_TAG = 'tagalys_config';

    /**
     * @var string
     */
    protected $_cacheTag = 'tagalys_config';

    /**
     * Prefix of model events names
     *
     * @var string
     */
    protected $_eventPrefix = 'tagalys_config';

    /**
     * Initialize resource model
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('Tagalys\Sync\Model\ResourceModel\Config');
    }

    public function checkPath($path)
    {
        return $this->_getResource()->checkPath($path);
    }

    public function getId()
    {
        return $this->getData(self::ID);
    }

    public function getPath()
    {
        return $this->getData(self::PATH);
    }

    public function getValue()
    {
        return $this->getData(self::VALUE);
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

    public function setPath($path)
    {
        return $this->setData(self::PATH, $path);
    }

    public function setValue($value)
    {
        return $this->setData(self::VALUE, $value);
    }
}