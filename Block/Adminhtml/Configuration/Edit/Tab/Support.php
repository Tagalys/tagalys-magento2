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
class Support extends Generic
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
        \Tagalys\Sync\Model\ConfigFactory $configFactory,
        \Magento\Catalog\Model\Product\Media\Config $productMediaConfig,
        \Magento\Framework\Module\Manager $moduleManager,
        array $data = []
    ) {
        $this->_yesNo = $yesNo;
        $this->propertyLocker = $propertyLocker;
        $this->tagalysConfiguration = $tagalysConfiguration;
        $this->tagalysApi = $tagalysApi;
        $this->configFactory = $configFactory;
        $this->filesystem = $context->getFilesystem();
        $this->productMediaConfig = $productMediaConfig;
        $this->moduleManager = $moduleManager;
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

        $supportfieldset = $form->addFieldset("supportFieldset", array('legend' => __("Support")));

        $supportfieldset->addField('support_email', 'note', array(
            'label' => __('Email'),
            'text' => '<a href="mailto:support@tagalys.com">support@tagalys.com</a>',
        ));

        $supportfieldset->addField('support_home', 'note', array(
            'label' => __('Documentation & FAQs'),
            'text' => '<a href="http://support.tagalys.com" target="_blank">http://support.tagalys.com</a>',
        ));

        $supportfieldset->addField('support_ticket', 'note', array(
            'label' => __('Support Tickets'),
            'text' => '<a href="http://support.tagalys.com/support/tickets/new" target="_blank">Submit a new Ticket</a><br><a href="http://support.tagalys.com/support/tickets" target="_blank">Check status</a>',
        ));

        $setupStatus = $this->tagalysConfiguration->getConfig('setup_status');
        if (in_array($setupStatus, array('sync', 'completed'))) {
            $tagalysCategoriesFieldset = $form->addFieldset('tagalys_categories_fieldset', array('legend' => __('Tagalys Categories')));

            $tagalysCategoriesFieldset->addField('retry_syncing_failed_categories', 'submit', array(
                'label' => '',
                'name' => 'tagalys_submit_action',
                'value' => 'Retry syncing failed categories',
                'onclick' => 'if (this.classList.contains(\'clicked\')) { return false; } else {  this.className += \' clicked\'; var that = this; setTimeout(function(){ that.value=\'Please wait…\'; that.disabled=true; }, 50); return true; }',
                'class'=> "tagalys-button-submit",
                'tabindex' => 1
            ));

            $tagalysSyncConfigFieldset = $form->addFieldset('tagalys_sync_config_fieldset', array('legend' => __('Configuration Sync')));

            $tagalysSyncConfigFieldset->addField('note_resync_config', 'note', array(
                'text' => __('This will trigger a resync of your configuration to Tagalys. Do this only with direction from Tagalys Support.')
            ));

            $tagalysSyncConfigFieldset->addField('submit_resync_config', 'submit', array(
                'label' => '',
                'name' => 'tagalys_submit_action',
                'value' => 'Trigger configuration resync now',
                'onclick' => 'if (this.classList.contains(\'clicked\')) { return false; } else {  this.className += \' clicked\'; var that = this; setTimeout(function(){ that.value=\'Please wait…\'; that.disabled=true; }, 50); return true; }',
                'class'=> "tagalys-button-submit",
                'tabindex' => 1
            ));

            $tagalysSyncConfigFieldset->addField('submit_refresh_tokens', 'submit', array(
                'label' => '',
                'name' => 'tagalys_submit_action',
                'value' => 'Refresh Access Token',
                'onclick' => 'if (this.classList.contains(\'clicked\')) { return false; } else {  this.className += \' clicked\'; var that = this; setTimeout(function(){ that.value=\'Please wait…\'; that.disabled=true; }, 50); return true; }',
                'class'=> "tagalys-button-submit",
                'tabindex' => 1
            ));

            $tagalysProductsSyncFieldset = $form->addFieldset('tagalys_full_resync_fieldset', array('legend' => __('Products Sync')));

            $productUpdateDetectionMethods = $this->tagalysConfiguration->getConfig('product_update_detection_methods', true);
            $updatedAtDetectionEnabled = false;
            if (in_array('db.catalog_product_entity.updated_at', $productUpdateDetectionMethods)) {
                $updatedAtDetectionEnabled = true;
            }

            $tagalysProductsSyncFieldset->addField('note_sync_mode', 'note', array(
                'label' => 'Detecting product updates',
                'text' => __('Tagalys automatically detects product updates made from the Magento Admin interface via the Catalog/Products, Catalog/Categories and Data Transfer/Import sections. If you are using third-party / custom code that doesn\'t dispatch the standard Magento hooks, there are two options to let us know:<ol><li>Trigger the "tagalys_record_updated_products" event</li><li>If your code updates the updated_at column of the catalog_product_entity table, you can enable monitoring of this field (currently <strong>'.($updatedAtDetectionEnabled ? 'On' : 'Off').'</strong>)</li></ol>')
            ));
            $tagalysProductsSyncFieldset->addField('submit_sync_mode', 'submit', array(
                'label' => '',
                'name' => 'tagalys_submit_action',
                'value' => ($updatedAtDetectionEnabled ? 'Disable' : 'Enable'). ' monitoring of catalog_product_entity.updated_at',
                'onclick' => 'if (this.classList.contains(\'clicked\')) { return false; } else {  this.className += \' clicked\'; var that = this; setTimeout(function(){ that.value=\'Please wait…\'; that.disabled=true; }, 50); return true; }',
                'class'=> "tagalys-button-submit",
                'tabindex' => 1
            ));

            $tagalysProductsSyncFieldset->addField('note_resync', 'note', array(
                'label' => 'Full Sync',
                'text' => __('This will trigger a full resync of your products to Tagalys. Do this only with direction from Tagalys Support. Please note that this will cause high CPU usage on your server. We recommend that you do this at low traffic hours.')
            ));

            $tagalysProductsSyncFieldset->addField('submit_resync', 'submit', array(
                'label' => '',
                'name' => 'tagalys_submit_action',
                'value' => 'Trigger full products resync now',
                'onclick' => 'if (this.classList.contains(\'clicked\')) { return false; } else {  this.className += \' clicked\'; var that = this; setTimeout(function(){ that.value=\'Please wait…\'; that.disabled=true; }, 50); return true; }',
                'class'=> "tagalys-button-submit",
                'tabindex' => 1
            ));

            $tagalysProductsSyncFieldset->addField('note_clear_sync_updates', 'note', array(
                'label' => __('Updates'),
                'text' => __('This will clear all product ids in Tagalys\' sync queue.')
            ));

            $tagalysProductsSyncFieldset->addField('submit_clear_sync_updates_queue', 'submit', array(
                'label' => '',
                'name' => 'tagalys_submit_action',
                'value' => 'Clear Tagalys sync queue',
                'onclick' => 'if (this.classList.contains(\'clicked\')) { return false; } else {  this.className += \' clicked\'; var that = this; setTimeout(function(){ that.value=\'Please wait…\'; that.disabled=true; }, 50); return true; }',
                'class'=> "tagalys-button-submit",
                'tabindex' => 1
            ));

            if ($this->moduleManager->isEnabled('Tagalys_Frontend')) {
                $tagalysUpdateCachesFieldset = $form->addFieldset('tagalys_update_caches_fieldset', array('legend' => __('Update Caches')));

                $tagalysUpdateCachesFieldset->addField('submit_update_popular_searches_cache', 'submit', array(
                    'label' => '',
                    'name' => 'tagalys_submit_action',
                    'value' => 'Update Popular Searches now',
                    'onclick' => 'if (this.classList.contains(\'clicked\')) { return false; } else {  this.className += \' clicked\'; var that = this; setTimeout(function(){ that.value=\'Please wait…\'; that.disabled=true; }, 50); return true; }',
                    'class'=> "tagalys-button-submit",
                    'tabindex' => 1
                ));
            }
        }

        $tagalysRestartSetupFieldset = $form->addFieldset('tagalysRestartSetupFieldset', array('legend' => __('Restart Tagalys Setup')));

        $tagalysRestartSetupFieldset->addField('note_restart_setup', 'note', array(
            'text' => __('<span class="error"><b>Caution:</b> This will disable Tagalys features and remove all Tagalys configuration from your Magento installation. To continue using Tagalys, you\'ll have to configure and sync products again. There is no undo.</span>')
        ));

        $tagalysRestartSetupFieldset->addField('submit_restart_setup', 'submit', array(
            'label' => '',
            'name' => 'tagalys_submit_action',
            'value' => 'Restart Tagalys Setup',
            'onclick' => 'if (confirm(\'Are you sure? This will disable Tagalys from your installation and you will have to start over. There is no undo.\')) { if (this.classList.contains(\'clicked\')) { return false; } else {  this.className += \' clicked\'; var that = this; setTimeout(function(){ that.value=\'Please wait…\'; that.disabled=true; }, 50); return true; } } else { return false; }',
            'class'=> "tagalys-button-submit",
            'tabindex' => 1
        ));

        $troubleshootingInfoFieldset = $form->addFieldset("troubleshootingInfoFieldset", array('legend' => __("Troubleshooting Info")));

        $troubleshootingInfoFieldset->addField('extension_version', 'note', array(
            'label' => 'Extention Version',
            'text' => $this->tagalysApi->getPluginVersion()
        ));

        $info = array('config' => array(), 'files_in_media_folder' => array());

        $queueCollection = $this->configFactory->create()->getCollection()->setOrder('id', 'ASC');
        foreach($queueCollection as $i) {
            $info['config'][$i->getData('path')] = $i->getData('value');
        }
        $mediaDirectory = $this->filesystem->getDirectoryRead('media')->getAbsolutePath('tagalys');
        if (!is_dir($mediaDirectory)) {
            mkdir($mediaDirectory);
        }
        $filesInMediaDirectory = scandir($mediaDirectory);
        foreach ($filesInMediaDirectory as $key => $value) {
            if (!is_dir($mediaDirectory . DIRECTORY_SEPARATOR . $value)) {
                if (!preg_match("/^\./", $value)) {
                    $info['files_in_media_folder'][] = $value;
                }
            }
        }

        $troubleshootingInfoFieldset->addField('troubleshooting_info', 'textarea', array(
            'name' => 'troubleshooting_info',
            'label' => 'Debug info',
            'readonly' => true,
            'value' => json_encode($info),
            'style' => "width:100%; height: 100px;",
            'after_element_html' => 'Please copy and send the above content to <a href="mailto:support@tagalys.com">support@tagalys.com</a> to help us troubleshoot issues.',
            'tabindex' => 1
        ));

        $this->setForm($form);
        // $this->propertyLocker->lock($form);
        return parent::_prepareForm();
    }
}
