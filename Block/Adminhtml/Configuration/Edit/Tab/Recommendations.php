<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Product attribute add/edit form main tab
 *
 * @author      Magento Core Team <core@magentocommerce.com>
 */
namespace Tagalys\Sync\Block\Adminhtml\Configuration\Edit\Tab;

use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Form;
use Magento\Backend\Block\Widget\Form\Generic;
use Magento\Config\Model\Config\Source\Yesno;
use Magento\Eav\Block\Adminhtml\Attribute\PropertyLocker;
use Magento\Framework\Data\FormFactory;
use Magento\Framework\Registry;

/**
 * @api
 * @since 100.0.2
 */
class Recommendations extends Generic
{
    protected $_yesNo;
    private $propertyLocker;
    private $tagalysConfiguration;
    private $tagalysApi;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param FormFactory $formFactory
     * @param Yesno $yesNo
     * @param PropertyLocker $propertyLocker
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        FormFactory $formFactory,
        Yesno $yesNo,
        PropertyLocker $propertyLocker,
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration,
        \Tagalys\Sync\Helper\Api $tagalysApi,
        array $data = []
    ) {
        $this->_yesNo = $yesNo;
        $this->propertyLocker = $propertyLocker;
        $this->tagalysConfiguration = $tagalysConfiguration;
        $this->tagalysApi = $tagalysApi;
        parent::__construct($context, $registry, $formFactory, $data);
    }

    /**
     * {@inheritdoc}
     * @return $this
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    protected function _prepareForm()
    {

        /** @var \Magento\Framework\Data\Form $form */
        $form = $this->_formFactory->create(
            ['data' => ['id' => 'edit_form', 'action' => $this->getData('action'), 'method' => 'post']]
        );

        // $yesnoSource = $this->_yesNo->toOptionArray();

        $recommendationsFieldset = $form->addFieldset(
            'mpages_fieldset',
            ['legend' => __('Recommendations'), 'collapsable' => $this->getRequest()->has('popup')]
        );

        $recommendationsFieldset->addField('enable_recommendations', 'select', array(
            'name' => 'enable_recommendations',
            'label' => 'Enable',
            'title' => 'Enable',
            'options' => array(
                '0' => __('No'),
                '1' => __('Yes'),
            ),
            'required' => true,
            'style' => 'width:100%',
            'value' => $this->tagalysConfiguration->getConfig("module:recommendations:enabled")
        ));

        $recommendationsFieldset->addField('note_integration', 'note', array(
            'label' => 'Integration',
            'text' => '<a href="http://support.tagalys.com/support/solutions/articles/26000020405-how-do-you-enable-tagalys-product-recommendations-for-magento-" target="_blank">Integration Documentation</a>',
        ));


        $recommendationsFieldset->addField('submit', 'submit', array(
            'name' => 'tagalys_submit_action',
            'value' => 'Save Recommendations Settings',
            'class' => 'tagalys-button-submit'
        ));

        $this->setForm($form);
        // $this->propertyLocker->lock($form);
        return parent::_prepareForm();
    }
}
