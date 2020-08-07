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
class Listingpages extends Generic
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
        \Tagalys\Sync\Helper\Category $tagalysCategory,
        \Tagalys\Sync\Helper\Api $tagalysApi,
        \Magento\Store\Model\StoreManagerInterface $storeManagerInterface,
        \Magento\Catalog\Model\CategoryFactory $categoryFactory,
        array $data = []
    ) {
        $this->_yesNo = $yesNo;
        $this->propertyLocker = $propertyLocker;
        $this->tagalysConfiguration = $tagalysConfiguration;
        $this->tagalysCategory = $tagalysCategory;
        $this->tagalysApi = $tagalysApi;
        $this->storeManagerInterface = $storeManagerInterface;
        $this->categoryFactory = $categoryFactory;
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

        $enableFieldSet = $form->addFieldset(
            'enable_listingpages_fieldset',
            ['legend' => __('Category Pages'),  'collapsable' => true]
        );

        $enableFieldSet->addField('enable_listingpages', 'select', array(
            'name' => 'enable_listingpages',
            'label' => 'Use Tagalys to power Category pages',
            'title' => 'Use Tagalys to power Category pages',
            'options' => array(
                '0' => __('No'),
                '1' => __('Yes - For selected category pages'),
                '2' => __('Yes - For all category pages')
            ),
            'required' => true,
            'style' => 'width:100%',
            'value' => $this->tagalysConfiguration->getConfig("module:listingpages:enabled")
        ));

        $enableFieldSet->addField("enable_smart_pages", 'select', array(
            'name' => "enable_smart_pages",
            'label' => "Allow Tagalys to create new pages",
            'options' => array(
                '1' => __('Yes'),
                '0' => __('No')
            ),
            'after_element_html' => '<p><small id="smart-pages-info" style="font-weight: bold">This will allow you to create new categories from the Tagalys Dashboard whose products are dynamically managed by Tagalys based on various conditions.</small></p>',
            'data-store-id' => 3,
            'style' => 'width:100%',
            'value'  => $this->tagalysConfiguration->getConfig("enable_smart_pages"),
        ));

        $technicalConsiderationsFieldset = $form->addFieldset(
            'technical_considerations_fieldset',
            ['legend' => __('Technical Considerations'), 'collapsable' => true]
        );

        $renderingMethod = $this->tagalysConfiguration->getConfig("listing_pages:rendering_method");
        if($renderingMethod == 'tagalys_js'){
            $technicalConsiderationsFieldset->addField('category_pages_rendering_method', 'select', array(
                'name' => 'category_pages_rendering_method',
                'label' => 'Render category pages UI using',
                'title' => 'Render category pages UI using',
                'options' => array(
                    'platform' => __('Magento'),
                    'tagalys_js' => __('JavaScript (Not recommended)')
                ),
                'required' => true,
                'style' => 'width:100%',
                'value' => $renderingMethod
            ));
        }

        //Magento Render mode
        $multiStoreWarningRequired = $this->tagalysCategory->isMultiStoreWarningRequired();
        $magentoNote = '<p>Tagalys operates by updating Magento product positions for the categories selected below. Your current values will be overwritten.</p>';
        if ($multiStoreWarningRequired) {
            $magentoNote .= "
                <p><strong>Important:</strong> You have multiple stores with common categories. Since Magento does not allow specifying separate product positions per store, please review the following:</p>
                <ul>
                    <li>If all or most of your products are common across all your stores, choose a single store below. Your category pages will be listed under this store on the Tagalys Dashboard and positions will be updated based on that store, but will apply to all your stores.</li>
                    <li>If you have different products across stores or have a more complex setup, please contact us on how best to choose categories below.</li>
                </ul>
            ";
        }
        $technicalConsiderationsFieldset->addField('rendering_method_note_platform', 'note', array(
            'label' => __('Notes for the tech team'),
            'text' => '<div class="tagalys-note visible-for-rendering-method visible-for-rendering-method-platform">'.$magentoNote.'</div>'
        ));
        if($multiStoreWarningRequired){
            $technicalConsiderationsFieldset->addField('same_or_similar_products_across_all_stores', 'select',  array(
                'label' => 'Are all or most of your products common across all stores?',
                'name' => 'same_or_similar_products_across_all_stores',
                'options' => array(
                    '1' => __('Yes, all or most of our products are common across all stores'),
                    '0' => __('No, we have different products across stores')
                ),
                'required' => true,
                'style' => 'width:100%',
                'class' => 'visible-for-rendering-method visible-for-rendering-method-platform',
                'value' => $this->tagalysConfiguration->getConfig("listing_pages:same_or_similar_products_across_all_stores")
            ));
            $storeOptions = array();
            foreach ($this->tagalysConfiguration->getStoresForTagalys() as $key => $storeId) {
                $store = $this->storeManagerInterface->getStore($storeId);
                $group = $store->getGroup();
                $website = $group->getWebsite();
                $storeDisplayLabel = $website->getName() . ' / '. $group->getName() . ' / ' . $store->getName();
                $storeOptions[$storeId] = $storeDisplayLabel;
            }
            $technicalConsiderationsFieldset->addField('store_id_for_category_pages', 'select',  array(
                'label' => 'Which store do you want to use to sync Category pages to Tagalys?',
                'name' => 'store_id_for_category_pages',
                'options' => $storeOptions,
                'required' => true,
                'style' => 'width:100%',
                'class' => 'visible-for-rendering-method visible-for-rendering-method-platform visible-for-same-products-across-stores visible-for-same-products-across-stores-1',
                'value' => $this->tagalysConfiguration->getConfig("listing_pages:store_id_for_category_pages")
            ));
        }
        $technicalConsiderationsFieldset->addField('position_sort_direction', 'select',  array(
            'label' => 'What direction of the Position field are category pages sorted by?',
            'name' => 'position_sort_direction',
            'options' => array(
                'asc' => 'Ascending (default)',
                'desc' => 'Descending'
            ),
            'required' => true,
            'style' => 'width:100%',
            'class' => 'visible-for-rendering-method visible-for-rendering-method-platform',
            'value' => $this->tagalysConfiguration->getConfig("listing_pages:position_sort_direction")
        ));

        // Tagalys Render mode
        $technicalConsiderationsFieldset->addField('note', 'note', array(
            'label' => __('Notes for the tech team'),
            'text' => '<ul class="tagalys-note visible-for-rendering-method visible-for-rendering-method-tagalys_js">
                <li>Tagalys will replace the template used to render the <em>category.products</em> block, so make sure that this is present in your layout</li>
                <li>Since Tagalys renders filters and products within this block, recommended settings are to override the layout and use <em>1column</em></li>
                <li>Overriding will make the page appear like categories for Tagalys are not Anchors and don\'t have children</li>
                <li>If you have some custom UI rules common to all Tagalys pages, then you could create a separate custom layout and override that instead of <em>1column</em></li>
                <li>If you need control over each page, avoid overriding the layout and use Magento controls under Catalog&nbsp;>&nbsp;Category for each page to specify the layout and any updates you need</li>
                <li>You may have to clear your Magento cache after updating these settings</li>
                <li>Please contact support@tagalys.com if you have any questions</li>
            </ul>'
        ));

        $technicalConsiderationsFieldset->addField('override_layout_for_listing_pages', 'select', array(
            'name' => 'override_layout_for_listing_pages',
            'label' => 'Override layout for Tagalys powered category pages',
            'title' => 'Override layout for Tagalys powered category pages',
            'options' => array(
                '0' => __('No'),
                '1' => __('Yes')
            ),
            'required' => true,
            'style' => 'width:100%',
            'class' => 'visible-for-rendering-method visible-for-rendering-method-tagalys_js',
            'value' => $this->tagalysConfiguration->getConfig("listing_pages:override_layout")
        ));

        $technicalConsiderationsFieldset->addField('override_layout_name_for_listing_pages', 'text', array(
            'name'      => 'override_layout_name_for_listing_pages',
            'label'     => __('Layout name to override with'),
            'value'  => $this->tagalysConfiguration->getConfig("listing_pages:override_layout_name"),
            'required'  => true,
            'style'   => "width:100%",
            'class' => 'visible-for-rendering-method visible-for-rendering-method-tagalys_js',
            'tabindex' => 1
        ));

        // Common
        $technicalConsiderationsFieldset->addField('understand_and_agree', 'text', array(
            'name'      => 'understand_and_agree',
            'label'     => 'Do you understand the above settings and agree to allow Tagalys to power category pages? Please verify with your tech team.',
            'value'  => $this->tagalysConfiguration->getConfig("listing_pages:understand_and_agree"),
            'required'  => true,
            'style'   => "width:100%",
            'after_element_html' => '<p><small style="font-weight: bold">Contact support@tagalys.com if you have any questions. <span style="color: #aa0000">Type "I agree" above to choose categories.</span></small></p>',
            'tabindex' => 1
        ));

        foreach ($this->tagalysConfiguration->getStoresForTagalys() as $key => $storeId) {
            if (!$this->tagalysConfiguration->isPrimaryStore($storeId)) {
                continue;
            }
            $storeDisplayLabel = $this->tagalysConfiguration->getStoreLabel($storeId);
            $smartPageParentCategoryId = $this->tagalysCategory->getTagalysParentCategory($storeId);
            $smartPageParentCategory = $this->categoryFactory->create()->setStoreId($storeId)->load($smartPageParentCategoryId);
            if($smartPageParentCategory->getId()){
                $smartPageEnabled = true;
            } else {
                $smartPageEnabled = false;
            }
            $storeListingPagesFieldset = $form->addFieldset(
                'store_'.$storeId.'_listing_pages',
                ['legend' => 'Categories for store: '.$storeDisplayLabel, 'collapsable' => true]
            );
            $storeListingPagesFieldset->addField("smart_page_parent_category_name_store_$storeId", 'text', array(
                'name' => "smart_page_parent_category_name_store_$storeId",
                'label' => "Smart Categories parent category name",
                'value'  => $smartPageParentCategory->getName(),
                'placeholder' => 'Buy (default)',
                'after_element_html' => '<p><small>For your reference, not shown in the front-end.</small></p>',
            ));
            $storeListingPagesFieldset->addField("smart_page_parent_category_url_key_store_$storeId", 'text', array(
                'name' => "smart_page_parent_category_url_key_store_$storeId",
                'label' => "Smart Categories parent category url_key",
                'value'  => $smartPageParentCategory->getUrlKey(),
                'placeholder' => 'buy (default)',
                'disabled' => $smartPageEnabled
            ));
            $categorySelectionDisplayData = $this->tagalysConfiguration->getCategorySelectionDisplayData($storeId);
            $storeListingPagesFieldset->addField("categories_for_tagalys_store_$storeId", 'multiselect', array(
                'name' => "categories_for_tagalys_store_$storeId",
                'onclick' => "return false;",
                'onchange' => "return false;",
                'class' => 'categories-for-tagalys-store',
                'value'  => $categorySelectionDisplayData['selected_paths'],
                'values' => $categorySelectionDisplayData['all_category_details'],
                'style' => "width:100%; height: 400px; display: none;",
                'disabled' => false,
                'readonly' => false,
                'tabindex' => 1
            ));
            $category_tree_data = htmlspecialchars($categorySelectionDisplayData['tree_data'], ENT_QUOTES, 'UTF-8');
            $storeListingPagesFieldset->addField("jtree_wrap_store_$storeId", 'note', array(
                'label' => '',
                'text'=>"<input id='categories-jtree-store-{$storeId}-q' /><button style='margin-left: 10px' id='select-all-category-store-{$storeId}' class='tagalys-btn'>Select all</button><button style='margin-left: 10px' id='deselect-all-category-store-{$storeId}' class='tagalys-btn'>Deselect all</button><div id='categories-jtree-store-{$storeId}' data-tree='{$category_tree_data}' ></div>"
            ));
        }

        $submitFieldset = $form->addFieldset(
            'save_listingpages',
            ['legend' => __('Save Changes')]
        );
        $submitFieldset->addField('save-delay-note', 'note', array(
            'label' => '',
            'text' => '<div class="tagalys-note">Once saved, selected categories will be visible in your Tagalys Dashboard within 10 minutes and product positions on these categories will be updated within 15 minutes unless otherwise mentioned.</div>'
        ));
        $submitFieldset->addField('submit', 'submit', array(
            'name' => 'tagalys_submit_action',
            'value' => 'Save Listing Pages Settings',
            'class'=> "tagalys-button-submit submit"
        ));

        $this->setForm($form);
        // $this->propertyLocker->lock($form);
        return parent::_prepareForm();
    }
}
