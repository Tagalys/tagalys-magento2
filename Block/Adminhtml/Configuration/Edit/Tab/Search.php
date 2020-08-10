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
class Search extends Generic
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

        $searchFieldset = $form->addFieldset(
            'search_fieldset',
            ['legend' => __('Search'), 'collapsable' => $this->getRequest()->has('popup')]
        );

        $searchFieldset->addField('enable_search', 'select', array(
            'name' => 'enable_search',
            'label' => 'Enable',
            'title' => 'Enable',
            'options' => array(
                '0' => __('No'),
                '1' => __('Yes'),
            ),
            'required' => true,
            'style' => 'width:100%',
            'value' => $this->tagalysConfiguration->getConfig("module:search:enabled")
        ));

        $storesForSearch = $this->tagalysConfiguration->getConfig('stores_for_search', true);
        $allWebsiteStores = $this->tagalysConfiguration->getAllWebsiteStores();
        $storesForTagalys = $this->tagalysConfiguration->getStoresForTagalys();
        $storesAvailableForSearch = array_filter($allWebsiteStores, function($storeData) use($storesForTagalys) {
            return in_array($storeData['value'], $storesForTagalys);
        });
        $searchFieldset->addField('stores_for_search', 'multiselect', array(
            'name'      => 'stores_for_search',
            'onclick' => "return false;",
            'onchange' => "return false;",
            'value'  => $storesForSearch,
            'values' => $storesAvailableForSearch,
            'style' => "width:100%; height: 125px; display: none;",
            'disabled' => false,
            'readonly' => false,
            'tabindex' => 1
        ));
        $store_tree_data = htmlspecialchars($this->tagalysConfiguration->getStoreTreeData($storesForSearch, $storesAvailableForSearch), ENT_QUOTES, 'UTF-8');
        $searchFieldset->addField('stores_for_search_jtree_wrap', 'note', array(
            'label' => __('Choose stores for which you want to enable Tagalys features'),
            'text'=>"<input id='stores-for-search-jtree-q'/><div id='stores-for-search-jtree' data-tree='{$store_tree_data}' ></div>"
        ));

        $searchFieldset->addField('search_box_selector', 'text', array(
            'name'      => 'search_box_selector',
            'label'     => __('Search box selector'),
            'value'  => $this->tagalysConfiguration->getConfig("search_box_selector"),
            'required'  => true,
            'style'   => "width:100%",
            'after_element_html' => '<small>Please consult with your tech team or <a href="mailto:support@tagalys.com">contact us</a>. <br>This can be any jQuery selector.<br>Eg: #search / .search-field / [type="search"]</small>',
            'tabindex' => 1
        ));

        $searchFieldset->addField('suggestions_align_to_parent_selector', 'text', array(
            'name'      => 'suggestions_align_to_parent_selector',
            'label'     => __('Align suggestions to search box parent'),
            'value'  => $this->tagalysConfiguration->getConfig("suggestions_align_to_parent_selector"),
            'required'  => false,
            'style'   => "width:100%",
            'after_element_html' => '<small>If you want to align the search suggestions popup under a parent of the search box instead of the search box itself, specify the selector here.<br>This can be any jQuery selector.<br>Eg: #search-and-icon-container</small>',
            'tabindex' => 1
        ));


        $searchFieldset->addField('submit', 'submit', array(
            'name' => 'tagalys_submit_action',
            'value' => 'Save Search Settings',
            'class' => 'tagalys-button-submit'
        ));

        $this->setForm($form);
        // $this->propertyLocker->lock($form);
        return parent::_prepareForm();
    }
}
