<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Tagalys\Sync\Block\Adminhtml\Configuration;

/**
 * Product attribute edit page
 *
 * @api
 * @since 100.0.2
 */
class Edit extends \Magento\Backend\Block\Widget\Form\Container
{
    protected $_blockGroup = 'Tagalys_Sync';
    protected $_coreRegistry = null;

    protected $_objectId;
    protected $_controller;

    /**
     * @param \Magento\Backend\Block\Widget\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param array $data
     */
    public function __construct(
        \Magento\Backend\Block\Widget\Context $context,
        \Magento\Framework\Registry $registry,
        array $data = []
    ) {
        $this->_coreRegistry = $registry;
        parent::__construct($context, $data);
    }

    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_objectId = 'id';
        $this->_controller = 'adminhtml_configuration';
        $this->_blockGroup  = 'tagalys_sync';
        parent::_construct();

        $this->buttonList->remove('save');
        $this->buttonList->remove('reset');

        // $this->_removeButton('save');
        // $this->_removeButton('back');
        // $this->_removeButton('reset');
    }

    /**
     * {@inheritdoc}
     */
    public function addButton($buttonId, $data, $level = 0, $sortOrder = 0, $region = 'toolbar')
    {
        if ($this->getRequest()->getParam('popup')) {
            $region = 'header';
        }
        parent::addButton($buttonId, $data, $level, $sortOrder, $region);
    }

    /**
     * Retrieve header text
     *
     * @return \Magento\Framework\Phrase
     */
    public function getHeaderText()
    {
        return $this->__('Configuration');
    }

    /**
     * Retrieve URL for validation
     *
     * @return string
     */
    // public function getValidationUrl()
    // {
    //     return $this->getUrl('catalog/*/validate', ['_current' => true]);
    // }

    /**
     * Retrieve URL for save
     *
     * @return string
     */
    // public function getSaveUrl()
    // {
    //     return $this->getUrl(
    //         'catalog/product_attribute/save',
    //         ['_current' => true, 'back' => null, 'product_tab' => $this->getRequest()->getParam('product_tab')]
    //     );
    // }
}
