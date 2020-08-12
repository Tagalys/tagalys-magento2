<?php
  namespace Tagalys\Sync\Controller\Adminhtml\Configuration;

class Edit extends \Magento\Backend\App\Action
{

    protected function _isAllowed()
    {
     return $this->_authorization->isAllowed('Tagalys_Sync::tagalys_configuration');
    }

    /**
    * @var \Magento\Framework\View\Result\PageFactory
    */
    protected $resultPageFactory;

    /**
     * Constructor
     *
     * @param \Magento\Backend\App\Action\Context $context
     * @param \Magento\Framework\View\Result\PageFactory $resultPageFactory
     */
    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration,
        \Tagalys\Sync\Helper\Category $tagalysCategoryHelper,
        \Tagalys\Sync\Helper\Api $tagalysApi,
        \Tagalys\Sync\Helper\Sync $tagalysSync,
        \Tagalys\Sync\Helper\Queue $queueHelper,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Catalog\Model\CategoryRepository $categoryRepository,
        \Tagalys\Sync\Model\CategoryFactory $tagalysCategoryFactory,
        \Magento\Indexer\Model\IndexerFactory $indexerFactory,
        \Magento\Catalog\Model\CategoryFactory $categoryFactory,
        \Magento\Framework\Module\Manager $moduleManager,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ) {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
        $this->tagalysConfiguration = $tagalysConfiguration;
        $this->tagalysCategoryHelper = $tagalysCategoryHelper;
        $this->tagalysApi = $tagalysApi;
        $this->tagalysSync = $tagalysSync;
        $this->messageManager = $context->getMessageManager();
        $this->queueHelper = $queueHelper;
        $this->storeManager = $storeManager;
        $this->categoryRepository = $categoryRepository;
        $this->tagalysCategoryFactory = $tagalysCategoryFactory;
        $this->indexerFactory = $indexerFactory;
        $this->categoryFactory = $categoryFactory;
        $this->moduleManager = $moduleManager;
        $this->scopeConfig = $scopeConfig;
        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/tagalys_core.log');
        $this->logger = new \Zend\Log\Logger();
        $this->logger->addWriter($writer);
        $this->platformDetailsToSend = [];
    }

    /**
     * Load the page defined in view/adminhtml/layout/exampleadminnewpage_helloworld_index.xml
     *
     * @return \Magento\Framework\View\Result\Page
     */
    public function execute()
    {
        $resultPage = $this->resultPageFactory->create();

        $params = $this->getRequest()->getParams();
        if (!empty($params['tagalys_submit_action'])) {
            $result = false;
            $redirectToTab = null;
            switch ($params['tagalys_submit_action']) {
                case 'Save API Credentials':
                    try {
                        $result = $this->_saveApiCredentials($params);
                        if ($result !== false) {
                            $this->tagalysApi->log('info', 'Saved API credentials', array('api_credentials' => $params['api_credentials']));
                            $setupStatus = $this->tagalysConfiguration->getConfig('setup_status');
                            if ($setupStatus == 'api_credentials') {
                                $setupStatus = $this->tagalysConfiguration->setConfig('setup_status', 'sync_settings');
                            }
                            $redirectToTab = 'sync';
                        } else {
                            $this->messageManager->addErrorMessage("Sorry, something went wrong while saving your API credentials. Please email us at support@tagalys.com so we can resolve this issue.");
                            $redirectToTab = 'api_credentials';
                        }
                    } catch (\Exception $e) {
                        $this->tagalysApi->log('error', 'Error in _saveApiCredentials', array('api_credentials' => $params['api_credentials']));
                        $this->messageManager->addErrorMessage("Sorry, something went wrong while saving your API credentials. Please email us at support@tagalys.com so we can resolve this issue.");
                        $redirectToTab = 'api_credentials';
                    }
                    break;
                case 'Save & Continue to Sync':
                    try {
                        if (array_key_exists('search_box_selector', $params)) {
                            $this->tagalysConfiguration->setConfig('search_box_selector', $params['search_box_selector']);
                            $this->tagalysConfiguration->setConfig('suggestions_align_to_parent_selector', $params['suggestions_align_to_parent_selector']);
                        }
                        if (array_key_exists('periodic_full_sync', $params)) {
                            $this->tagalysConfiguration->setConfig('periodic_full_sync', $params['periodic_full_sync']);
                        }
                        if (array_key_exists('product_image_attribute', $params)) {
                            $this->tagalysConfiguration->setConfig('product_image_attribute', $params['product_image_attribute']);
                        }
                        if (array_key_exists('product_image_hover_attribute', $params)) {
                            $this->tagalysConfiguration->setConfig('product_image_hover_attribute', $params['product_image_hover_attribute']);
                        }
                        if (array_key_exists('max_product_thumbnail_width', $params)) {
                            $this->tagalysConfiguration->setConfig('max_product_thumbnail_width', $params['max_product_thumbnail_width']);
                        }
                        if (array_key_exists('max_product_thumbnail_height', $params)) {
                            $this->tagalysConfiguration->setConfig('max_product_thumbnail_height', $params['max_product_thumbnail_height']);
                        }
                        if (array_key_exists('product_thumbnail_quality', $params)) {
                            $this->tagalysConfiguration->setConfig('product_thumbnail_quality', $params['product_thumbnail_quality']);
                        }
                        if (array_key_exists('stores_for_tagalys', $params) && count($params['stores_for_tagalys']) > 0) {
                            $this->tagalysApi->log('info', 'Starting configuration sync', array('stores_for_tagalys' => $params['stores_for_tagalys']));
                            $response = $this->tagalysConfiguration->syncClientConfiguration($params['stores_for_tagalys']);
                            if ($response === false || $response['result'] === false) {
                                $message = "Sorry, something went wrong while saving your store's configuration. Contact us for support";
                                if ($response !== false && array_key_exists('message', $response)) {
                                    $message = $response['message'];
                                }
                                $this->tagalysApi->log('error', 'syncClientConfiguration returned false', array('stores_for_tagalys' => $params['stores_for_tagalys']));
                                $this->messageManager->addErrorMessage($message);
                                $redirectToTab = 'sync_settings';
                            } else {
                                $this->tagalysApi->log('info', 'Completed configuration sync', array('stores_for_tagalys' => $params['stores_for_tagalys']));
                                $this->tagalysConfiguration->setConfig('stores', json_encode($params['stores_for_tagalys']));
                                foreach($params['stores_for_tagalys'] as $i => $storeId) {
                                    $this->tagalysSync->triggerFeedForStore($storeId);
                                }
                                $setupStatus = $this->tagalysConfiguration->getConfig('setup_status');
                                if ($setupStatus == 'sync_settings') {
                                    $this->tagalysConfiguration->setConfig('setup_status', 'sync');
                                }
                                $redirectToTab = 'sync';
                            }
                        } else {
                            $this->messageManager->addErrorMessage("Please choose at least one store to continue.");
                            $redirectToTab = 'sync_settings';
                        }
                    } catch (\Exception $e) {
                        $this->tagalysApi->log('error', 'Error in syncClientConfiguration: ' . $e->getMessage(), array('stores_for_tagalys' => $params['stores_for_tagalys']));
                        $this->messageManager->addErrorMessage("Sorry, something went wrong while saving your configuration. Please email us at support@tagalys.com so we can resolve this issue.");
                        $redirectToTab = 'sync_settings';
                    }
                    break;
                case 'Save Search Settings':
                    $this->tagalysConfiguration->setConfig('module:search:enabled', $params['enable_search']);
                    $this->tagalysConfiguration->setConfig('stores_for_search', $params['stores_for_search'], true);
                    $this->tagalysConfiguration->setConfig('search_box_selector', $params['search_box_selector']);
                    $this->tagalysConfiguration->setConfig('suggestions_align_to_parent_selector', $params['suggestions_align_to_parent_selector']);
                    $this->tagalysConfiguration->setConfig('search:override_layout_name', $params['search_override_layout_name']);
                    $this->tagalysApi->log('warn', 'search:enabled:'.$params['enable_search']);
                    $redirectToTab = 'search';
                    break;
                case 'Save Listing Pages Settings':
                    $this->tagalysConfiguration->setConfig('module:listingpages:enabled', $params['enable_listingpages']);
                    if ($params['enable_listingpages'] != '0' && $params['understand_and_agree'] == 'I agree') {
                        $this->messageManager->addNoticeMessage("Settings have been saved. Selected categories will be visible in your Tagalys Dashboard within 10 minutes and product positions on these categories will be updated within 15 minutes unless specificed below.");
                        if (!array_key_exists('category_pages_rendering_method', $params)){
                            $params['category_pages_rendering_method'] = 'platform';
                        }
                        $this->tagalysConfiguration->setConfig('listing_pages:rendering_method', $params['category_pages_rendering_method']);
                        $this->tagalysConfiguration->setConfig('listing_pages:position_sort_direction', $params['position_sort_direction']);
                        $this->tagalysConfiguration->setConfig('listing_pages:understand_and_agree', $params['understand_and_agree']);
                        $this->tagalysConfiguration->setConfig("enable_smart_pages", $params["enable_smart_pages"]);
                        $categoryProductIndexer = $this->indexerFactory->create()->load('catalog_category_product');
                        foreach($params['stores_for_tagalys'] as $storeId) {
                            $this->platformDetailsToSend['platform_pages_rendering_method'] = $params['category_pages_rendering_method'];
                            $this->platformDetailsToSend['magento_category_products_indexer_mode'] = ($categoryProductIndexer->isScheduled() ? 'update_by_schedule' : 'update_on_save');
                            if (!array_key_exists('categories_for_tagalys_store_' . $storeId, $params)) {
                                $params[ 'categories_for_tagalys_store_' . $storeId] = array();
                            }
                            if($params["enable_smart_pages"] == 1){
                                try{
                                    $this->saveSmartPageParentCategory($storeId, $params);
                                } catch(\Exception $e) {
                                    $this->messageManager->addErrorMessage("Error while saving Smart category: ".$e->getMessage());
                                    $this->logger->err(json_encode(["saveSmartPageParentCategory: failed", $e->getMessage(), $e->getTrace()]));
                                }
                            }
                            $this->tagalysApi->storeApiCall($storeId . '', '/v1/stores/update_platform_details', ['platform_details' => $this->platformDetailsToSend]);
                            $originalStoreId = $this->storeManager->getStore()->getId();
                            $this->storeManager->setCurrentStore($storeId);
                            $categoryIds = array();
                            if ($params['enable_listingpages'] == '2'){
                                /* don't do anything here as powering all categories for all stores could take some time.
                                    the sync cron will do it's job anyway. */
                            } else {
                                // CLARIFY: should we remove this check?
                                if (count($params['categories_for_tagalys_store_'. $storeId]) > 0) {
                                    foreach($params['categories_for_tagalys_store_' . $storeId] as $categoryPath) {
                                        $path = explode('/', $categoryPath);
                                        $categoryIds[] = intval(end($path));
                                    }
                                    foreach ($categoryIds as $categoryId) {
                                        try {
                                            $category = $this->categoryRepository->get($categoryId, $storeId);
                                            if ($this->tagalysCategoryHelper->isTagalysCreated($category)){
                                                continue; // skip if tagalys category - we don't show them in the front-end and marked_for_deletion should not apply
                                            }
                                        } catch (\Exception $e) {
                                            continue;
                                        }
                                        if ($category->getDisplayMode() == 'PAGE') {
                                            // skip
                                            $firstItem = $this->tagalysCategoryFactory->create()->getCollection()
                                                ->addFieldToFilter('store_id', $storeId)
                                                ->addFieldToFilter('category_id', $categoryId)
                                                ->getFirstItem();
                                            if ($id = $firstItem->getId()) {
                                                $firstItem->addData(array('marked_for_deletion' => 1))->save();
                                            }
                                        } else {
                                            $this->tagalysCategoryHelper->markCategoryForSyncIfRequired($storeId, $categoryId);
                                        }
                                    }
                                }
                                $this->tagalysCategoryHelper->markStoreCategoryIdsToDisableExcept($storeId, $categoryIds);
                            }
                            $this->storeManager->setCurrentStore($originalStoreId);
                        }
                        if ($params['category_pages_rendering_method'] == 'platform') {
                            $this->tagalysConfiguration->setConfig('listing_pages:categories_via_tagalys_js_enabled', '0');
                            if (array_key_exists('same_or_similar_products_across_all_stores', $params)) {
                                $this->tagalysConfiguration->setConfig('listing_pages:same_or_similar_products_across_all_stores', $params['same_or_similar_products_across_all_stores']);
                                $this->tagalysConfiguration->setConfig('listing_pages:store_id_for_category_pages', $params['store_id_for_category_pages']);
                            }
                            foreach($params['stores_for_tagalys'] as $storeId) {
                                $originalStoreId = $this->storeManager->getStore()->getId();
                                $this->storeManager->setCurrentStore($storeId);
                                if (
                                    array_key_exists('same_or_similar_products_across_all_stores', $params) && $params['same_or_similar_products_across_all_stores'] == '1' &&
                                    $storeId.'' != $params['store_id_for_category_pages'].''
                                ) {
                                    $this->tagalysCategoryHelper->markStoreCategoryIdsForDeletionExcept($storeId, array());
                                    continue;
                                }
                                $this->storeManager->setCurrentStore($originalStoreId);
                            }
                        } else {
                            $this->tagalysConfiguration->setConfig('listing_pages:categories_via_tagalys_js_enabled', '1');
                            $this->tagalysConfiguration->setConfig('listing_pages:override_layout', $params['override_layout_for_listing_pages']);
                            $this->tagalysConfiguration->setConfig('listing_pages:override_layout_name', $params['override_layout_name_for_listing_pages']);
                        }
                    } else {
                        if ($params['enable_listingpages'] != '0') {
                            $this->messageManager->addErrorMessage("Settings have not been updated because you did not type 'I agree'.");
                        }
                        $this->tagalysConfiguration->setConfig('listing_pages:categories_via_tagalys_js_enabled', '0');
                    }
                    $redirectToTab = 'listingpages';
                    break;
                case 'Save Recommendations Settings':
                    $this->tagalysConfiguration->setConfig('module:recommendations:enabled', $params['enable_recommendations']);
                    $this->tagalysApi->log('warn', 'search:enabled:'.$params['enable_recommendations']);
                    $redirectToTab = 'recommendations';
                    break;
                case 'Save My Store Settings':
                    $this->tagalysConfiguration->setConfig('module:mystore:enabled', $params['enable_mystore']);
                    $this->tagalysApi->log('warn', 'search:enabled:'.$params['enable_mystore']);
                    $redirectToTab = 'mystore';
                    break;
                case 'Retry syncing failed categories':
                    $this->tagalysApi->log('warn', 'Retrying failed categories sync');
                    $this->tagalysCategoryHelper->markFailedCategoriesForRetrying();
                    $redirectToTab = 'support';
                    break;
                case 'Update Popular Searches now':
                    $this->tagalysApi->log('warn', 'Triggering update popular searches');
                    if ($this->moduleManager->isEnabled('Tagalys_Frontend')) {
                        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
                        $tagalysSearchHelper = $objectManager->create('Tagalys\Frontend\Helper\Search');
                        $tagalysSearchHelper->cachePopularSearches();
                    }
                    $redirectToTab = 'support';
                    break;
                case 'Trigger full products resync now':
                    $triggered = true;
                    $this->tagalysApi->log('warn', 'Triggering full products resync');
                    try{
                        $this->tagalysSync->triggerFullSync();
                    } catch (\Exception $e){
                        $triggered = false;
                        $this->messageManager->addErrorMessage("Unable to trigger a full resync. There is already a sync in progress.");
                    }
                    if ($triggered){
                        $this->queueHelper->truncate();
                    }
                    $redirectToTab = 'support';
                    break;
                case 'Clear Tagalys sync queue':
                    $this->tagalysApi->log('warn', 'Clearing Tagalys sync queue');
                    $this->queueHelper->truncate();
                    $redirectToTab = 'support';
                    break;
                case 'Trigger configuration resync now':
                    $this->tagalysApi->log('warn', 'Triggering configuration resync');
                    $this->tagalysConfiguration->setConfig("config_sync_required", '1');
                    $redirectToTab = 'support';
                    break;
                case 'Restart Tagalys Setup':
                    $this->tagalysApi->log('warn', 'Restarting Tagalys Setup');
                    $this->queueHelper->truncate();
                    $this->tagalysConfiguration->truncate();
                    $this->tagalysCategoryHelper->truncate();
                    $this->tagalysConfiguration->deleteIntegration();
                    $redirectToTab = 'api_credentials';
                    break;
                case 'Enable monitoring of catalog_product_entity.updated_at':
                    $productUpdateDetectionMethods = $this->tagalysConfiguration->setConfig('product_update_detection_methods', array('events', 'db.catalog_product_entity.updated_at'), true);
                    $redirectToTab = 'support';
                    break;
                case 'Disable monitoring of catalog_product_entity.updated_at':
                    $productUpdateDetectionMethods = $this->tagalysConfiguration->setConfig('product_update_detection_methods', array('events'), true);
                    $redirectToTab = 'support';
                    break;
                case 'Refresh Access Token':
                    try {
                        $this->tagalysConfiguration->deleteIntegration();
                        $res = $this->tagalysConfiguration->syncClientConfiguration();
                        if ($res) {
                            if ($res['result'] === true) {
                                $this->tagalysApi->log('warn', 'Refresh Access Token');
                                $this->messageManager->addNoticeMessage("Successfully refreshed access tokens");
                            } else {
                                $message = $res['message'];
                                $this->messageManager->addErrorMessage("Unable to save new token. Response: $message");
                            }
                        } else {
                            $this->messageManager->addErrorMessage("Unable to save new token");
                        }
                    } catch (\Throwable $e) {
                        $this->messageManager->addErrorMessage("Refreshing token failed. Message: {$e->getMessage()}");
                    }
                    $redirectToTab = 'support';
                    break;
            }
            return $this->_redirect('tagalys/configuration/edit/active_tab/'.$redirectToTab);
        }

        return  $resultPage;
    }

    protected function _saveApiCredentials($params)
    {
        $result = $this->tagalysApi->identificationCheck(json_decode($params['api_credentials'], true));
        if ($result['result'] != 1) {
            $this->messageManager->addErrorMessage("Invalid API Credentials. Please try again. If you continue having issues, please email us at support@tagalys.com.");
            return false;
        }
        // save credentials
        $this->tagalysConfiguration->setConfig('api_credentials', $params['api_credentials']);
        $this->tagalysApi->cacheApiCredentials();
        return true;
    }

    private function saveSmartPageParentCategory($storeId, $params) {
        if($this->tagalysConfiguration->isPrimaryStore($storeId)){
            $categoryId = $this->tagalysCategoryHelper->getTagalysParentCategory($storeId);
            $mappedStores = $this->tagalysConfiguration->getMappedStores($storeId, true);
            $categoryDetails = [];
            if ($params["smart_page_parent_category_name_store_$storeId"] == "") {
                $categoryDetails['name'] = 'Buy';
            } else {
                $categoryDetails['name'] = $params["smart_page_parent_category_name_store_$storeId"];
            }
            if (array_key_exists("smart_page_parent_category_url_key_store_$storeId", $params)) {
                if ($params["smart_page_parent_category_url_key_store_$storeId"] == ""){
                    $categoryDetails['url_key'] = 'buy';
                } else {
                    $categoryDetails['url_key'] = strtolower($params["smart_page_parent_category_url_key_store_$storeId"]);
                }
            }
            foreach ($mappedStores as $mappedStoreId) {
                if ($categoryId) {
                    $this->tagalysCategoryHelper->updateCategoryDetails($categoryId, $categoryDetails, $mappedStoreId);
                } else {
                    $categoryId = $this->tagalysCategoryHelper->createTagalysParentCategory($storeId, $categoryDetails);
                }
                $category = $this->categoryFactory->create()->setStoreId($mappedStoreId)->load($categoryId);
                $this->platformDetailsToSend['parent_category_id'] = $category->getId();
                $this->platformDetailsToSend['parent_category_name'] = $category->getName();
                $urlKey = $category->getUrlKey();
                $urlSuffix = $this->scopeConfig->getValue('catalog/seo/category_url_suffix', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $mappedStoreId);
                $this->tagalysApi->clientApiCall('/v1/mpages/update_base_url', ['url_key' => $urlKey, 'store_id' => $mappedStoreId, 'url_suffix' => $urlSuffix]);
            }
        }
    }
}