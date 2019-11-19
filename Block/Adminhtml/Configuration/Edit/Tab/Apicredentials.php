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
class Apicredentials extends Generic
{
    /**
     * @var Yesno
     */
    protected $_yesNo;

    /**
     * @var PropertyLocker
     */
    private $propertyLocker;

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

        $dashboardFieldset = $form->addFieldset(
            'dashboard_fieldset',
            ['legend' => __('Tagalys Dashboard'), 'collapsable' => $this->getRequest()->has('popup')]
        );
        $dashboardFieldset->addField('note', 'note', array(
            'label' => __('Your Tagalys account'),
            'text' => '<img src=\'https://www.tagalys.com/wp-content/themes/tagalys/img/logo.png\' alt="" width="125" />'.'<br>',
        ));
        $setupStatus = $this->tagalysConfiguration->getConfig('setup_status');
        if ($setupStatus == 'api_credentials') {
            $dashboardFieldset->addField('note_dashboard', 'note', array(
                'text' => '<a href="https://next.tagalys.com/signup?platform='. $this->tagalysApi->platformIdentifier() .'" target="_blank" class="tagalys-button-important">Sign up for a Tagalys account</a>'
            ));
        } else {
            $dashboardFieldset->addField('note_dashboard', 'note', array(
                'text' => '<a href="https://next.tagalys.com" target="_blank" class="tagalys-button-important">Access your Tagalys Dashboard</a>'
            ));
        }

        $mainFieldset = $form->addFieldset(
            'main_fieldset',
            ['legend' => __('API Credentials'), 'collapsable' => $this->getRequest()->has('popup')]
        );

        $mainFieldset->addField(
            'api_credentials',
            'textarea',
            [
                'name' => 'api_credentials',
                'label' => __('API Credentials'),
                'title' => __('API Credentials'),
                'value' => $this->tagalysConfiguration->getConfig('api_credentials'),
            ]
        );

        $mainFieldset->addField('submit', 'submit', array(
            'name' => 'tagalys_submit_action',
            'value' => 'Save API Credentials',
            'class' => 'tagalys-button-submit'
        ));

        $this->setForm($form);
        // $this->propertyLocker->lock($form);
        return parent::_prepareForm();
    }
}
