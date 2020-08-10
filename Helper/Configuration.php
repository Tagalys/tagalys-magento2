<?php
namespace Tagalys\Sync\Helper;

class Configuration extends \Magento\Framework\App\Helper\AbstractHelper
{

    public $tagalysCoreFields = array("__id", "name", "sku", "link", "sale_price", "image_url", "introduced_at", "in_stock");

    public $cachedCategoryNames = [];

    /**
     * @param \Magento\Framework\App\ProductMetadataInterface
     */
    private $productMetadataInterface;

    public function __construct(
        \Magento\Framework\Stdlib\DateTime\DateTime $datetime,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $timezoneInterface,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfigInterface,
        \Magento\Directory\Model\Currency $currency,
        \Magento\Directory\Model\CurrencyFactory $currencyFactory,
        \Magento\Catalog\Model\ProductFactory $productFactory,
        \Magento\Catalog\Model\ResourceModel\Eav\Attribute $attributeFactory,
        \Magento\Catalog\Model\Config $configModel,
        \Magento\Catalog\Model\Category $categoryModel,
        \Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory $attributeCollectionFactory,
        \Magento\Review\Model\ResourceModel\Rating\CollectionFactory $ratingCollectionFactory,
        \Tagalys\Sync\Model\ConfigFactory $configFactory,
        \Tagalys\Sync\Helper\Api $tagalysApi,
        \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $categoryCollection,
        \Tagalys\Sync\Model\CategoryFactory $tagalysCategoryFactory,
        \Magento\Integration\Model\IntegrationFactory $integrationFactory,
        \Magento\Integration\Model\Oauth\Token $oauthToken,
        \Magento\Integration\Model\AuthorizationService $authorizationService,
        \Magento\Integration\Model\OauthService $oauthService,
        \Magento\Framework\Event\Manager $eventManager,
        \Magento\Framework\App\ProductMetadataInterface $productMetadataInterface
    )
    {
        $this->datetime = $datetime;
        $this->timezoneInterface = $timezoneInterface;
        $this->storeManager = $storeManager;
        $this->scopeConfigInterface = $scopeConfigInterface;
        $this->currency = $currency;
        $this->currencyFactory = $currencyFactory;
        $this->attributeFactory = $attributeFactory;
        $this->attributeCollectionFactory = $attributeCollectionFactory;
        $this->configModel = $configModel;
        $this->categoryModel = $categoryModel;
        $this->productFactory = $productFactory;
        $this->ratingCollectionFactory = $ratingCollectionFactory;
        $this->configFactory = $configFactory;
        $this->tagalysApi = $tagalysApi;
        $this->categoryCollection = $categoryCollection;
        $this->tagalysCategoryFactory = $tagalysCategoryFactory;
        $this->integrationFactory = $integrationFactory;
        $this->oauthToken = $oauthToken;
        $this->authorizationService = $authorizationService;
        $this->oauthService = $oauthService;
        $this->eventManager = $eventManager;
        $this->productMetadataInterface = $productMetadataInterface;
    }

    public function isTagalysEnabledForStore($storeId, $module = false) {
        $storesForTagalys = $this->getStoresForTagalys();
        if (in_array($storeId, $storesForTagalys)) {
            if ($module === false) {
                return true;
            } else {
                $config = $this->getConfig("module:$module:enabled");
                if ($config != '0' && $config != null) {
                    return true;
                }
            }
        }
        return false;
    }

    public function checkStatusCompleted() {
        $setupStatus = $this->getConfig('setup_status');
        if ($setupStatus == 'sync') {
            $storeIds = $this->getStoresForTagalys();
            $allStoresCompleted = true;
            foreach($storeIds as $storeId) {
                $storeSetupStatus = $this->getConfig("store:$storeId:setup_complete");
                if ($storeSetupStatus != '1') {
                    $allStoresCompleted = false;
                    break;
                }
            }
            if ($allStoresCompleted) {
                $this->setConfig("setup_status", 'completed');
                $this->tagalysApi->log('info', 'All stores synced. Setup complete.', array());
            }
        }
    }

    public function getConfig($configPath, $jsonDecode = false) {
        $configValue = $this->configFactory->create()->load($configPath)->getValue();
        if ($configValue === NULL) {
            $defaultConfigValues = array(
                'setup_status' => 'api_credentials',
                'search_box_selector' => '#search',
                'max_product_thumbnail_width' => '400',
                'max_product_thumbnail_height' => '400',
                'cron_heartbeat_sent' => false,
                'suggestions_align_to_parent_selector' => '',
                'periodic_full_sync' => '1',
                'module:mpages:enabled' => '1',
                'categories'=> '[]',
                'category_ids'=> '[]',
                'listing_pages:override_layout'=> '1',
                'listing_pages:override_layout_name'=> '1column',
                'listing_pages:position_sort_direction' => 'asc',
                'listing_pages:rendering_method' => 'platform',
                'product_image_attribute' => 'small_image',
                'product_image_hover_attribute' => '',
                'product_thumbnail_quality' => '80',
                'category_pages_rendering_method' => 'magento',
                'tagalys_plan_features' => '{}',
                'legacy_mpage_categories' => '[]',
                'category_pages_store_mapping' => '{}',
                'integration_permissions' => '["Tagalys_Sync::tagalys"]',
                'product_update_detection_methods' => '["events"]',
                'use_optimized_product_updated_at' => 'true',
                'listing_pages:clear_cache_after_reindex' => 'false',
                'listing_pages:reindex_category_product_after_updates' => 'false',
                'listing_pages:reindex_category_flat_after_updates' => 'false',
                'listing_pages:update_position_via_db' => 'false',
                'listing_pages:update_smart_category_products_via_db' => 'true',
                'listing_pages:update_position_async' => 'true',
                'listing_pages:consider_multi_store_during_position_updates' => 'true',
                'sync:reindex_products_before_updates' => 'false',
                'sync:log_product_ids_during_insert_to_queue' => 'false',
                'sync:insert_primary_products_in_insert_unique' => 'true',
                'success_order_states' => '["new", "payment_review", "processing", "complete", "closed"]',
                'sync:record_price_rule_updates_for_each_product' => 'false',
                'sync:use_get_final_price_for_sale_price' => 'false',
                'module:listingpages:enabled' => '0',
                'analytics:main_configurable_attribute' => '',
                'sync:multi_source_inventory_used' => 'false',
                'sync:whitelisted_product_attributes' => '[]',
                'stores_for_search' => '[]',
                'sync:read_boolean_attributes_via_db' => 'false'
            );
            if (array_key_exists($configPath, $defaultConfigValues)) {
                $configValue = $defaultConfigValues[$configPath];
            } else {
                $configValue = NULL;
            }
        }
        if ($configValue !== NULL && $jsonDecode) {
            return json_decode($configValue, true);
        }
        return $configValue;
    }

    public function getPlanFeature($feature) {
      $features = $this->getConfig('tagalys_plan_features', true);
      return $features[$feature];
    }

    public function setConfig($configPath, $configValue, $jsonEncode = false) {
        if ($jsonEncode) {
            $configValue = json_encode($configValue);
        }
        try {
            $config = $this->configFactory->create();
            if ($config->checkPath($configPath)) {
                $found = $config->load($configPath);
                $found->setValue($configValue);
                $found->save();
            } else {
                $config->setPath($configPath);
                $config->setValue($configValue);
                $config->save();
            }
        } catch (\Exception $e){
            $this->tagalysApi->log('error', 'Exception in setConfig', array('exception_message' => $e->getMessage()));
        }
    }

    public function truncate() {
        $config = $this->configFactory->create();
        $connection = $config->getResource()->getConnection();
        $tableName = $config->getResource()->getMainTable();
        $connection->truncateTable($tableName);
    }

    public function getStoresForTagalys($includeDefault = false) {
        $storesForTagalys = $this->getConfig("stores", true);

        if ($storesForTagalys != NULL) {
            if (!is_array($storesForTagalys)) {
                $storesForTagalys = array($storesForTagalys);
            }
            if ($includeDefault){
                $storesForTagalys[] = '0';
            }
            return $storesForTagalys;
        }
        return array();
    }

    public function getAllCategories($storeId, $includeTagalysCreated = false) {
        $output = [];
        $originalStoreId = $this->storeManager->getStore()->getId();
        $this->storeManager->setCurrentStore($storeId);
        $categories = $this->getCategoryCollection($storeId, $includeTagalysCreated);
        foreach ($categories as $category) {
            $pathIds = explode('/', $category->getPath());
            if (count($pathIds) > 2) {
                $label = $this->getCategoryName($category);
                $output[] = array(
                    'id' => $category->getId(),
                    'value' => implode('/', $pathIds),
                    'label' => $label,
                    'static_block_only' => ($category->getDisplayMode()=='PAGE')
                );
            }
        }
        $this->storeManager->setCurrentStore($originalStoreId);
        return $output;
    }

    public function getCategoryName($category) {
        $pathNames = array();
        $pathIds = array_filter(explode('/', $category->getPath()), function($pathId) {
            return $pathId !== '1';
        });
        if (count($pathIds) > 1) { // skip the "Root Category"
            $newPathIds = array_filter($pathIds, function($pathId) {
                return !array_key_exists($pathId, $this->cachedCategoryNames);
            });
            if (!empty($newPathIds)) {
                $pathCategories = $this->categoryCollection->create()->addAttributeToSelect('*')->addFieldToFilter('entity_id', array('in' => $newPathIds));
                foreach($pathCategories as $pathCategory) {
                    $this->cachedCategoryNames[$pathCategory->getId()] = $pathCategory->getName();
                }
            }
            foreach($pathIds as $id){
                try {
                    // CLARIFY: Is try catch needed?
                    if (array_key_exists($id, $this->cachedCategoryNames)){
                        $pathNames[] = $this->cachedCategoryNames[$id];
                    } else {
                        $pathNames[] = '(NA)';
                    }
                } catch (\Exception $th) {
                    $pathNames[] = '(N/A)';
                }
            }
        }
        return implode(' |>| ', $pathNames);
    }

    public function getStoreTreeData($selectedStores, $stores){
        $tree = array();
        foreach($stores as $store){
            $selected = false;
            foreach($selectedStores as $selected_store){
                if($store['value']==$selected_store){
                    $selected = true;
                    $tree[]=array('id'=>$store['value'], 'value'=>$store['value'], 'text'=>$store['label'], 'state'=>array('selected'=>true));
                }
            }
            if(!$selected){
                $tree[]=array('id'=>$store['value'], 'value'=>$store['value'], 'text'=>$store['label'], 'state' => array('selected' => false));
            }
        }
        return json_encode($tree);
    }

    public function getCategorySelectionDisplayData($storeId) {
        $allCategoriesDetails = $this->getAllCategories($storeId);
        $selectedCategoryDetails = $this->getSelectedCategoryDetails($storeId, $allCategoriesDetails);
        $selectedCategoryPaths = array_map(function($selectedCategory) {
            return $selectedCategory['path'];
        }, $selectedCategoryDetails);
        return [
            'all_category_details' => $allCategoriesDetails,
            'selected_paths' => $selectedCategoryPaths,
            'tree_data' => $this->getCategoryTreeData($selectedCategoryDetails, $allCategoriesDetails)
        ];
    }

    public function getSelectedCategoryDetails($storeId, $allCategoryDetails) {
        $tagalysCategories = $this->tagalysCategoryFactory->create()->getCollection()
            ->addFieldToFilter('store_id', $storeId)
            ->addFieldToFilter('status',['nin' => ['pending_disable']])
            ->addFieldToFilter('marked_for_deletion', '0')
            ->addFieldToSelect('*');
        $selectedCategoryDetails = [];
        foreach ($tagalysCategories as $tagalysCategory) {
            $id = $tagalysCategory->getCategoryId();
            $details = Configuration::findByKey('id', $id, $allCategoryDetails);
            if ($details) {
                $selectedCategoryDetails[$id] = [
                    'id' => $id,
                    'status' => $tagalysCategory->getStatus(),
                    'positions_synced_at' => $tagalysCategory->getPositionSyncedAt(),
                    'path' => $details['value']
                ];
            }
        }
        return $selectedCategoryDetails;
    }

    public function getCategoryTreeData($selectedCategoryDetails, $flat_category_list){
        $tree = array();
        foreach ($flat_category_list as $category){
            if (array_key_exists($category['id'], $selectedCategoryDetails)) {
                $selectedCategory = $selectedCategoryDetails[$category['id']];
                $category['selected'] = true;
                $category['status'] = $selectedCategory['status'];
                $category['positions_synced_at'] = $selectedCategory['positions_synced_at'];
            }
            $category_id_path = explode('/',$category['value']);
            $category_label_path = explode(' |>| ',$category['label']);
            if($category_id_path[0] == 1){
                array_splice($category_id_path, 0, 1);
            }
            $tree = $this->constructTree($category_id_path, $category_label_path, $tree, $category);
        }
        return json_encode($tree);
    }

    private function constructTree($category_id_path, $category_label_path, $children, $category_object){
        if(count($category_id_path)==1){
        // Append to array
        $node_exist = false;
        for($i=0;$i<count($children);$i++){
            if($children[$i]['id']==$category_id_path[0]){
            $node_exist = true;
            $children[$i]['value'] = $category_object['value'];
            $children[$i]['state'] = array();
            $children[$i]['state']['disabled'] = $category_object['static_block_only'];
            if(array_key_exists('selected', $category_object) && $category_object['selected']==true){
                $children[$i]['state']['selected'] = true;
                $iconAndText = $this->getCategoryStatusIconAndText($category_object);
                $children[$i]['icon'] = $iconAndText['icon'];
                if ($iconAndText['icon'] != 'hidden') {
                    $children[$i]['text'] .= $iconAndText['text'];
                }
            }
            }
        }
        if(!$node_exist){
            $node = array(
                'id'=>$category_id_path[0],
                'value'=>$category_object['value'],
                'text'=>$category_label_path[0].($category_object['static_block_only'] ? ' (Static block only)' : ''),
                'state'=> array('selected'=> (array_key_exists('selected',$category_object) && $category_object['selected']==true) ? true : false, 'disabled' => $category_object['static_block_only']),
                'children'=>array(),
                'icon' => $this->getCategoryStatusIconAndText($category_object)
            );
            $iconAndText = $this->getCategoryStatusIconAndText($category_object);
            $node['icon'] = $iconAndText['icon'];
            if ($iconAndText['icon'] != 'hidden') {
                $node['text'] .= $iconAndText['text'];
            }
            $children[] = $node;
        }
        } else {
        // Find the parent to pass to
        $child_exist = false;
        for($i=0;$i<count($children);$i++){
            if($children[$i]['id']==$category_id_path[0]){
            $child_exist = true;
            $children[$i]['children']=$this->constructTree(
                array_slice($category_id_path, 1),
                array_slice($category_label_path, 1),
                $children[$i]['children'],
                $category_object
            );
            break;
            }
        }
        if(!$child_exist){
            // Create the parent
            $children[]=array(
                'id'=>$category_id_path[0],
                'value'=> 'NOT_AVAILABLE',
                'text' => $category_label_path[0].($category_object['static_block_only'] ? ' (Static block only)' : ''),
                'state' => array('disabled' => true, 'opened' => true), // Only for ROOT (eg. defautl category) categories
                'children' => $this->constructTree(array_slice($category_id_path, 1), array_slice($category_label_path, 1), array(), $category_object),
                'icon' => 'hidden'
            );
        }
        }
        return $children;
    }

    private function getCategoryStatusIconAndText($categoryObject){
        if(!array_key_exists('status',$categoryObject)){
            $categoryObject['status'] = '';
        }
        switch($categoryObject['status']){
            case 'pending_sync':
                return array('icon' => 'fa fa-refresh', 'text' => ' (Sync Pending)');
            case 'failed':
                return array('icon' => 'fa fa-exclamation-triangle', 'text' => ' (No Products Found)');
            case 'powered_by_tagalys':
                if ($categoryObject['positions_synced_at'] == NULL) {
                return array('icon' => 'fa fa-refresh', 'text' => ' (Sync Pending)');
                } else {
                return array('icon' => 'fa fa-bolt', 'text' => ' (Powered by Tagalys)');
                }
            case 'tagalys_created':
                return array('icon' => 'fa fa-bolt', 'text' => ' (Created by Tagalys)');
            default:
                return array('icon' => 'hidden', 'text' => '');
        }
    }

    public function getAllWebsiteStores() {
        $website_stores = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            foreach ($website->getGroups() as $group) {
                $stores = $group->getStores();
                foreach ($stores as $store) {
                    $website_stores[] = array("value" => $store->getId(), "label" => $website->getName()." / ".$group->getName(). " / ".$store->getName());
                }
            }
        }
        return $website_stores;
    }

    public function syncClientConfiguration($storeIds = false) {
        if ($storeIds === false) {
            $storeIds = $this->getStoresForTagalys();
        }
        $clientConfiguration = array('stores' => array());
        foreach ($storeIds as $index => $storeId) {
            $clientConfiguration['stores'][] = $this->getStoreConfiguration($storeId);
        }
        $tagalysResponse = $this->tagalysApi->clientApiCall('/v1/configuration', $clientConfiguration);
        if ($tagalysResponse === false) {
            return false;
        }
        if ($tagalysResponse['result'] == true) {
            if (!empty($tagalysResponse['product_sync_required'])) {
                foreach ($tagalysResponse['product_sync_required'] as $storeId => $required) {
                    $this->setConfig("store:{$storeId}:resync_required", (int)$required);
                }
            }
        }
        return $tagalysResponse;
    }

    public function getStoreConfiguration($storeId) {
        $store = $this->storeManager->getStore($storeId);
        $tagSetsAndCustomFields = $this->getTagSetsAndCustomFields($store->getId());
        $productsCount = $this->productFactory->create()->getCollection()->setStoreId($storeId)->addStoreFilter($storeId)->addAttributeToFilter('status', 1)->addAttributeToFilter('visibility', array("neq" => 1))->count();
        $storeUrl = $this->storeManager->getStore($storeId)->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB, true);
        $storeDomain = parse_url($storeUrl)['host'];
        $urlSuffix = $this->scopeConfigInterface->getValue('catalog/seo/category_url_suffix', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
        $configuration = array(
            'id' => $storeId,
            'label' => $store->getName(),
            'locale' => $this->scopeConfigInterface->getValue('general/locale/code', 'store', $storeId),
            'multi_currency_mode' => 'exchange_rate',
            'timezone' => $this->timezoneInterface->getConfigTimezone('store', $store),
            'currencies' => $this->getCurrencies($store),
            'fields' => $tagSetsAndCustomFields['custom_fields'],
            'tag_sets' => $tagSetsAndCustomFields['tag_sets'],
            'sort_options' =>  $this->getSortOptions($storeId),
            'products_count' => $productsCount,
            'domain' => $storeDomain,
            'base_url' => $storeUrl,
            'platform_details' => [
                'plugin_version' => $this->tagalysApi->getPluginVersion(),
                'access_token' => $this->getAccessToken(),
                'url_suffix' => $urlSuffix,
            ]
        );

        $configurationObj = new \Magento\Framework\DataObject(array('configuration' => $configuration, 'store_id' => $storeId));
        $this->eventManager->dispatch('tagalys_read_store_configuration', ['tgls_data' => $configurationObj]);
        $configuration = $configurationObj->getConfiguration();

        return $configuration;
    }

    public function getSortOptions($storeId) {
        $sort_options = array();
        foreach ($this->configModel->getAttributesUsedForSortBy() as $key => $attribute) {
            $sort_options[] = array(
                'field' => $attribute->getAttributeCode(),
                'label' => $attribute->getStoreLabel($storeId)
            );
        }
        return $sort_options;
    }

    public function getCurrencies($store, $onlyDefault = false) {
        $currencies = array();
        $codes = $store->getAvailableCurrencyCodes();
        $rates = $this->currency->getCurrencyRates(
            $store->getBaseCurrencyCode(),
            $codes
        );
        $baseCurrencyCode = $store->getBaseCurrencyCode();
        $defaultCurrencyCode = $store->getDefaultCurrencyCode();
        if (empty($rates[$baseCurrencyCode])) {
            $rates[$baseCurrencyCode] = '1.0000';
        }
        foreach ($codes as $code) {
            if (isset($rates[$code])) {
                $defaultCurrency = ($defaultCurrencyCode == $code ? true : false);
                $thisCurrency = $this->currencyFactory->create()->load($code);
                $label = $thisCurrency->getCurrencySymbol();
                if (empty($label)) {
                    $label = $code;
                }
                $currencies[] = array(
                    'id' => $code,
                    'label' => $label,
                    'exchange_rate' => $rates[$code],
                    'rounding_mode' => 'round',
                    'fractional_digits' => 2,
                    'default' => $defaultCurrency
                );
                if ($onlyDefault && $defaultCurrency) {
                    return end($currencies);
                }
            }
        }
        return $currencies;
    }

    public function getCurrenciesByCode($store) {
        $currencies = $this->getCurrencies($store);
        $currenciesByCode = array();
        foreach ($currencies as $currency) {
            $currenciesByCode[$currency['id']] = $currency;
        }
        return $currenciesByCode;
    }


    public function getTagSetsAndCustomFields($storeId) {
        $tag_sets = array();
        $tag_sets[] = array("id" =>"__categories", "label" => "Categories", "filters" => true, "search" => true);
        $custom_fields = array();
        $custom_fields[] = array(
            'name' => '__new',
            'label' => 'New',
            'type' => 'boolean',
            'currency' => false,
            'display' => true,
            'filters' => false,
            'search' => false
        );
        $custom_fields[] = array(
            'name' => '__inventory_total',
            'label' => 'Inventory - Total',
            'type' => 'float',
            'currency' => false,
            'display' => false,
            'filters' => false,
            'dashboard_filters' => true,
            'search' => false
        );
        $custom_fields[] = array(
            'name' => '__inventory_average',
            'label' => 'Inventory - Average',
            'type' => 'float',
            'currency' => false,
            'display' => false,
            'filters' => false,
            'dashboard_filters' => true,
            'search' => false
        );
        $custom_fields[] = array(
            'name' => '__magento_type',
            'label' => 'Magento Product Type',
            'type' => 'string',
            'currency' => false,
            'display' => true,
            'filters' => false,
            'search' => false
        );
        $custom_fields[] = array(
            'name' => '__magento_ratings_count',
            'label' => 'Magento Ratings Count',
            'type' => 'float',
            'currency' => false,
            'display' => true,
            'filters' => false,
            'search' => false
        );
        foreach($this->ratingCollectionFactory->create() as $rating) {
            $custom_fields[] = array(
                'name' => ('__magento_avg_rating_id_'.$rating->getId()),
                'label' => ('Magento Ratings Average: '.$rating->getRatingCode()),
                'type' => 'float',
                'currency' => false,
                'display' => true,
                'filters' => false,
                'search' => false
            );
        }
        $magento_tagalys_type_mapping = array(
            'text' => 'string',
            'textarea' => 'string',
            'date' => 'datetime',
            'boolean' => 'boolean',
            'multiselect' => 'string',
            'select' => 'string',
            'price' => 'float'
        );
        $attributes = $this->attributeCollectionFactory->create()->addVisibleFilter();
        $whitelistedAttributes = $this->getConfig('sync:whitelisted_product_attributes', true);
        foreach($attributes as $attribute) {
            if ($this->shouldSyncAttribute($attribute, $whitelistedAttributes)) {
                $isForDisplay = ((bool)$attribute->getUsedInProductListing() && (bool)$attribute->getIsUserDefined());
                if ($this->isAttributeCustomField($attribute)) {
                    $isPriceField = ($attribute->getFrontendInput() == "price" );
                    if (array_key_exists($attribute->getFrontendInput(), $magento_tagalys_type_mapping)) {
                        $type = $magento_tagalys_type_mapping[$attribute->getFrontendInput()];
                    } else {
                        $type = 'string';
                    }
                    $custom_fields[] = array(
                        'name' => $attribute->getAttributeCode(),
                        'label' => $attribute->getStoreLabel($storeId),
                        'type' => $type,
                        'currency' => $isPriceField,
                        'display' => ($isForDisplay || $isPriceField),
                        'filters' => (bool)$attribute->getIsFilterable(),
                        'search' => (bool)$attribute->getIsSearchable()
                    );
                }
                if ($this->isAttributeTagSet($attribute)) {
                    $tag_sets[] = array(
                        'id' => $attribute->getAttributeCode(),
                        'label' => $attribute->getStoreLabel($storeId),
                        'filters' => (bool)$attribute->getIsFilterable(),
                        'search' => (bool)$attribute->getIsSearchable(),
                        'display' => $isForDisplay
                    );
                }
            }
        }
        return compact('tag_sets', 'custom_fields');
    }

    public function shouldSyncAttribute($attribute, $whitelistedAttributes = false, $blacklistedAttributes = []){
        $attributeCode = $attribute->getAttributeCode();
        $blacklistedAttributes = array_merge($blacklistedAttributes,['status', 'tax_class_id']);
        if (in_array($attributeCode, $blacklistedAttributes)){
            return false;
        }
        if ($attribute->getIsFilterable() || $attribute->getIsSearchable()) {
            return true;
        }
        $isUserDefined = (bool)$attribute->getIsUserDefined();
        $isForDisplay = ($isUserDefined && (bool)$attribute->getUsedInProductListing());
        if ($isForDisplay) {
            return true;
        }
        $isNecessarySystemAttribute = (!$isUserDefined && in_array($attributeCode, ['visibility', 'url_key']));
        if ($isNecessarySystemAttribute) {
            return true;
        }
        if(!$whitelistedAttributes) {
            $whitelistedAttributes = $this->getConfig('sync:whitelisted_product_attributes', true);
        }
        return in_array($attributeCode, $whitelistedAttributes);
    }

    public function isAttributeCustomField($attribute){
        return $this->isAttributeField($attribute) && !$this->isAttributeCoreField($attribute);
    }

    public function isAttributeField($attribute){
        return $attribute->getFrontendInput() != 'multiselect';
    }

    public function isAttributeCoreField($attribute){
        return in_array($attribute->getAttributeCode(), $this->tagalysCoreFields);
    }

    public function isAttributeTagSet($attribute){
        return ($attribute->usesSource() && $attribute->getFrontendInput() != 'boolean');
    }

    public function isProductSortingReverse(){
        return $this->getConfig('listing_pages:position_sort_direction') != 'asc';
    }

    public function isPrimaryStore($storeId){
        $storeMapping = $this->getConfig("category_pages_store_mapping", true);
        if (array_key_exists($storeId . '', $storeMapping) && $storeMapping[$storeId . ''] != $storeId . '') {
            return false;
        }
        return true;
    }

    public function getMappedStores($storeId, $includeMe = false) {
        $stores = [];
        if ($includeMe) {
            $stores[] = $storeId;
        }
        $storeMapping = $this->getConfig("category_pages_store_mapping", true);
        foreach ($storeMapping as $child => $parent) {
            if ($parent == $storeId){
                $stores[] = $child;
            }
        }
        return $stores;
    }

    public function getStoreLabel($storeId){
        $storeDisplayLabel = $this->getConfig("store_{$storeId}_display_label");
        if (!$storeDisplayLabel){
            $store = $this->storeManager->getStore($storeId);
            $group = $store->getGroup();
            $website = $group->getWebsite();
            $storeDisplayLabel = $website->getName() . ' / ' . $group->getName() . ' / ' . $store->getName();
        }
        return $storeDisplayLabel;
    }

    public function getAccessToken(){
        $integrationData = array(
            'name' => 'Tagalys',
            'email' => 'support@tagalys.com',
            'status' => '1',
            'endpoint' => '',
            'setup_type' => '0'
        );
        $integration = $this->integrationFactory->create()->load($integrationData['name'], 'name');
        if(empty($integration->getData())){
            // Code to create Integration
            $integration = $this->integrationFactory->create();
            $integration->setData($integrationData);
            $integration->save();
            $integrationId = $integration->getId();
            $consumerName = 'Integration' . $integrationId;
            // Code to create consumer
            $consumer = $this->oauthService->createConsumer(['name' => $consumerName]);
            $consumerId = $consumer->getId();
            $integration->setConsumerId($consumer->getId());
            $integration->save();
            // Code to grant permission
            $this->authorizationService->grantPermissions($integrationId, $this->getConfig('integration_permissions', true));
            // Code to Activate and Authorize
            $this->oauthToken->createVerifierToken($consumerId);
            $this->oauthToken->setType('access');
            $this->oauthToken->save();
            $accessToken = $this->oauthToken->getToken();
            return $accessToken;
        } else {
            $accessToken = $this->oauthToken->loadByConsumerIdAndUserType($integration->getConsumerId(), 1)->getToken();
            return $accessToken;
        }
    }

    public function deleteIntegration() {
        $integration = $this->integrationFactory->create()->load('Tagalys', 'name');
        $integration->delete();
    }

    public function areChildSimpleProductsVisibleIndividually() {
        $mainConfigurableAttribute = $this->getConfig('analytics:main_configurable_attribute');
        return ($mainConfigurableAttribute != '');
    }

    public function getAllVisibleAttributesForAPI(){
        $attributeData = [];
        $attributes = $this->attributeCollectionFactory->create()->addVisibleFilter();
        $whitelistedAttributes = $this->getConfig('sync:whitelisted_product_attributes', true);
        foreach ($attributes as $attribute) {
            $attributeCode = $attribute->getAttributeCode();
            $isUserDefined = (bool)$attribute->getIsUserDefined();
            $usedInListingPage = (bool)$attribute->getUsedInProductListing();
            $isForDisplay = ($isUserDefined && $usedInListingPage);
            $isNecessarySystemAttribute = (!$isUserDefined && in_array($attributeCode, ['visibility', 'url_key']));
            $attributeData[] = [
                'attribute_code' => $attributeCode,
                'attribute_label' => $attribute->getStoreLabel(0),
                'is_user_defined' => $isUserDefined,
                'is_filterable' => (bool) $attribute->getIsFilterable(),
                'is_searchable' => (bool) $attribute->getIsSearchable(),
                'is_for_display' => $isForDisplay,
                'is_necessary_system_attribute' => $isNecessarySystemAttribute,
                'is_white_listed' => in_array($attributeCode, $whitelistedAttributes),
                'should_sync_attribute' => $this->shouldSyncAttribute($attribute, $whitelistedAttributes)
            ];
        }
        return $attributeData;
    }

    public static function getInstanceOf($class) {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        return $objectManager->create($class);
    }

    public static function findByKey($key, $value, $list) {
        foreach($list as $item) {
            if (array_key_exists($key, $item) && $item[$key] == $value) {
                return $item;
            }
        }
        return false;
    }

    public function isTSearchEnabled($storeId) {
        $moduleEnabled = $this->isTagalysEnabledForStore($storeId, 'search');
        if($moduleEnabled){
            $storesForSearch = $this->getConfig('stores_for_search', true);
            return in_array($storeId, $storesForSearch);
        }
        return false;
    }

    public function getAllStoreWithWebsites(){
        $stores = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            foreach ($website->getGroups() as $group) {
                foreach ($group->getStores() as $store) {
                    $stores[$store->getId()] = [
                        'store' => $store,
                        'group' => $group,
                        'website' => $website
                    ];
                }
            }
        }
        return $stores;
    }

    public function getAllStoresForAPI() {
        $storesData = [];
        $storesForTagalys = $this->getStoresForTagalys();
        $storesForSearch = $this->getConfig('stores_for_search', true);
        $storesWithWebsites = $this->getAllStoreWithWebsites();
        foreach ($storesWithWebsites as $storeId => $storeWithWebsite) {
            $website = $storeWithWebsite['website'];
            $group = $storeWithWebsite['group'];
            $store = $storeWithWebsite['store'];
            $poweredByTagalys = in_array($storeId, $storesForTagalys);
            $storesData[] = [
                'id' => $storeId,
                'name' => $store->getName(),
                'group_code' => $group->getCode(),
                'group_name' => $group->getName(),
                'website_code' => $website->getCode(),
                'website_name' => $website->getName(),
                'root_category_id' => $store->getRootCategoryId(),
                'powered_by_tagalys' => $poweredByTagalys,
                'search_enabled' => ($poweredByTagalys && in_array($storeId, $storesForSearch)),
            ];
        }
        return $storesData;
    }

    public function getCategoryCollection($storeId, $includeTagalysCreated = true) {
        $rootCategoryId = $this->storeManager->getStore($storeId)->getRootCategoryId();
        $categories = $this->categoryCollection->create()
            ->setStoreId($storeId)
            ->addFieldToFilter('is_active', 1)
            ->addAttributeToFilter('path', array('like' => "1/{$rootCategoryId}/%"))
            ->addAttributeToSelect('*');
        if (!$includeTagalysCreated) {
            $tagalysParentId = Configuration::getInstanceOf('Tagalys\Sync\Helper\Category')->getTagalysParentCategory($storeId);
            $categories->addAttributeToFilter('path', array('nlike' => "1/{$rootCategoryId}/{$tagalysParentId}/%"));
        }
        return $categories;
    }

    public function getAllCategoriesForAPI($storeId, $includeTagalysCreated, $processAncestry) {
        $response = [];
        $allCategoriesDetails = $this->getAllCategories($storeId, $includeTagalysCreated);
        $tagalysPoweredCategories = $this->getSelectedCategoryDetails($storeId, $allCategoriesDetails);
        $categories = $this->getCategoryCollection($storeId, $includeTagalysCreated);
        foreach ($categories as $category) {
            $id = $category->getId();
            $categoryDetails = array(
                'id' => $id,
                'name' => $category->getName(),
                'slug' => $category->getUrlKey(),
                'path' => $category->getPath(),
                'label' => $processAncestry ? $this->getCategoryName($category) : false,
                'static_block_only' => ($category->getDisplayMode()=='PAGE')
            );
            if (array_key_exists($id, $tagalysPoweredCategories)) {
                $categoryDetails['powered_by_tagalys'] = true;
                $categoryDetails['tagalys_data'] = $tagalysPoweredCategories[$id];
            } else {
                $categoryDetails['powered_by_tagalys'] = false;
            }
            $response[$id] = $categoryDetails;
        }
        return $response;
    }

    public function getResourceColumnToJoin(){
        $edition = $this->productMetadataInterface->getEdition();
        if ($edition == "Community") {
            $columnToJoin = 'entity_id';
        } else {
            $columnToJoin = 'row_id';
        }
        return $columnToJoin;
    }
}
