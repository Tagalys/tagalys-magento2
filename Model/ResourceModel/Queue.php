<?php

namespace Tagalys\Sync\Model\ResourceModel;

class Queue extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{

    /**
     * @var \Magento\Framework\Stdlib\DateTime\DateTime
     */
    protected $_date;

    /**
     * Construct
     *
     * @param \Magento\Framework\Model\ResourceModel\Db\Context $context
     * @param \Magento\Framework\Stdlib\DateTime\DateTime $date
     * @param string|null $resourcePrefix
     */
    public function __construct(
        \Magento\Framework\Model\ResourceModel\Db\Context $context,
        \Magento\Framework\Stdlib\DateTime\DateTime $date,
        $resourcePrefix = null
    ) {
        parent::__construct($context, $resourcePrefix);
        $this->_date = $date;
    }

    /**
     * Initialize resource model
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('tagalys_queue', 'id');
    }

    /**
     * Process config data before saving
     *
     * @param \Magento\Framework\Model\AbstractModel $object
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function _beforeSave(\Magento\Framework\Model\AbstractModel $object)
    {

        // if (!$this->isValidPostUrlKey($object)) {
        //     throw new \Magento\Framework\Exception\LocalizedException(
        //         __('The config URL key contains capital letters or disallowed symbols.')
        //     );
        // }

        // if ($this->isNumericPostUrlKey($object)) {
        //     throw new \Magento\Framework\Exception\LocalizedException(
        //         __('The config URL key cannot be made of only numbers.')
        //     );
        // }

        // if ($object->isObjectNew() && !$object->hasCreationTime()) {
        //     $object->setCreationTime($this->_date->gmtDate());
        // }

        // $object->setUpdateTime($this->_date->gmtDate());

        return parent::_beforeSave($object);
    }

    protected function _getLoadByProductIdSelect($productId)
    {
        $select = $this->getConnection()->select()->from(
            ['tq' => $this->getMainTable()]
        )->where(
            'tq.product_id = ?',
            $productId
        );

        return $select;
    }

    public function doesProductIdExist($productId)
    {
        $select = $this->_getLoadByProductIdSelect($productId);
        $select->reset(\Zend_Db_Select::COLUMNS)->columns('tq.id')->limit(1);

        return $this->getConnection()->fetchOne($select);
    }
}