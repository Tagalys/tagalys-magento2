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
class Sync extends Generic
{
    protected $_yesNo;
    private $propertyLocker;
    private $tagalysConfiguration;
    private $tagalysApi;
    private $storeManager;

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
        $this->storeManager = $context->getStoreManager();
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

        $setupStatus = $this->tagalysConfiguration->getConfig('setup_status');
        $setupComplete = ($setupStatus == 'completed');

        $syncFieldset = $form->addFieldset(
            'sync_fieldset',
            ['legend' => __('Sync Status'), 'collapsable' => $this->getRequest()->has('popup')]
        );
        $syncFieldset->addField("sync_status_note", 'note', array(
            'label' => 'Status',
            'text' => '<span id="note_sync_status"></span>'
        ));

        foreach ($this->tagalysConfiguration->getStoresForTagalys() as $key => $storeId) {
            $store = $this->storeManager->getStore($storeId);
            $storeLabel = $store->getWebsite()->getName()." / ".$store->getGroup()->getName(). " / ".$store->getName();
            $storeSyncFieldset = $form->addFieldset(
                "store_{$storeId}_fieldset",
                ['legend' => __("Store: " . $storeLabel), 'collapsable' => $this->getRequest()->has('popup')]
            );
            $storeSyncFieldset->addField("store_{$storeId}_note_setup_complete", 'note', array(
                'label' => 'Setup complete',
                'text' => '<span id="store_'.$storeId.'_note_setup_complete"></span>'
            ));
            $storeSyncFieldset->addField("store_{$storeId}_note_feed_status", 'note', array(
                'label' => 'Feed Status',
                'text' => '<span id="store_'.$storeId.'_note_feed_status"></span>'
            ));
            $storeSyncFieldset->addField("store_{$storeId}_note_updates_status", 'note', array(
                'label' => 'Updates Status',
                'text' => '<span id="store_'.$storeId.'_note_updates_status"></span>'
            ));
            $storeSyncFieldset->addField("store_{$storeId}_note_listing_pages_status", 'note', array(
                'label' => 'Category Pages',
                'text' => '<span id="store_'.$storeId.'_note_listing_pages_status"></span>'
            ));
        }

        $this->setForm($form);
        // $this->propertyLocker->lock($form);
        return parent::_prepareForm();
    }
}
