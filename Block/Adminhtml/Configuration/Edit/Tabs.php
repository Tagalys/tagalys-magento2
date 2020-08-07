<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Adminhtml product attribute edit page tabs
 *
 * @author     Magento Core Team <core@magentocommerce.com>
 */
namespace Tagalys\Sync\Block\Adminhtml\Configuration\Edit;

/**
 * @api
 * @since 100.0.2
 */
class Tabs extends \Magento\Backend\Block\Widget\Tabs
{

    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        \Magento\Framework\Json\EncoderInterface $jsonEncoder,
        \Magento\Backend\Model\Auth\Session $authSession,
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration,
        \Magento\Framework\Module\Manager $moduleManager,
        \Magento\Framework\App\Request\Http $request,
        array $data = []
    ) {
        $this->tagalysConfiguration = $tagalysConfiguration;
        $this->moduleManager = $moduleManager;
        $this->request = $request;
        parent::__construct($context, $jsonEncoder, $authSession, $data);
    }

    /**
     * @return void
     */
    protected function _construct()
    {
        parent::_construct();
        $this->setId('tagalys_configuration_tabs');
        $this->setDestElementId('edit_form');
        $this->setTitle(__('Configuration'));
    }

    /**
     * @return $this
     */
    protected function _beforeToHtml()
    {
        $setupStatus = $this->tagalysConfiguration->getConfig('setup_status');
        $setupComplete = ($setupStatus == 'completed');
        $stepNumber = 1;

        $this->addTab(
            'api_credentials',
            [
                'label' => $setupComplete ? __('Dashboard & API Credentials') : __('Step '.$stepNumber.': API Credentials'),
                'title' => $setupComplete ? __('Dashboard & API Credentials') : __('Step '.$stepNumber.': API Credentials'),
                'content' => $this->getChildHtml('api_credentials'),
                'active' => true
            ]
        );
        $stepNumber++;

        if (in_array($setupStatus, array('sync_settings', 'sync', 'completed'))) {
            $this->addTab('sync_settings', array(
                'label' => $setupComplete ? __('Sync Settings') : __('Step '.$stepNumber.': Sync Settings'),
                'title' => $setupComplete ? __('Sync Settings') : __('Step '.$stepNumber.': Sync Settings'),
                'content' => $this->getChildHtml('sync_settings'),
            ));
            $stepNumber++;
        }

        if (in_array($setupStatus, array('sync', 'completed'))) {
            $this->addTab('sync', array(
                'label' => $setupComplete ? __('Sync Status') : __('Step '.$stepNumber.': Sync'),
                'title' => $setupComplete ? __('Sync Status') : __('Step '.$stepNumber.': Sync'),
                'content' => $this->getChildHtml('sync'),
            ));
            $stepNumber++;
        }

        if (!$setupComplete) {
            // go to current status tab
            $this->setActiveTab($setupStatus);
        } else {
            $this->addTab('listingpages', array(
                'label' => __('Category Pages'),
                'title' => __('Category Pages'),
                'content' => $this->getChildHtml('listingpages'),
            ));
            if ($this->moduleManager->isEnabled('Tagalys_Frontend')) {
                $this->addTab('search', array(
                    'label' => __('Search'),
                    'title' => __('Search'),
                    'content' => $this->getChildHtml('search'),
                ));
            }
            if ($this->moduleManager->isEnabled('Tagalys_Mystore')) {
                $this->addTab('mystore', array(
                    'label' => __('My Store'),
                    'title' => __('My Store'),
                    'content' => $this->getChildHtml('mystore'),
                ));
            }

            $tabParam = $this->request->getParam('tab');
            if ($tabParam != null) {
                $this->setActiveTab($tabParam);
            }
        }

        $this->addTab('support', array(
            'label' => __('Support & Troubleshooting'),
            'title' => __('Support & Troubleshooting'),
            'content' => $this->getChildHtml('support'),
        ));

        return parent::_beforeToHtml();
    }
}
