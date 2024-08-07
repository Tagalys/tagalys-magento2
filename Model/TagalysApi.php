<?php

namespace Tagalys\Sync\Model;

use Tagalys\Sync\Api\TagalysManagementInterface;
use Tagalys\Sync\Helper\Utils;

class TagalysApi implements TagalysManagementInterface
{
    private $tagalysApi;
    private $configFactory;
    private $tagalysCategoryFactory;
    private $filesystem;
    private $_registry;
    private $queueHelper;
    private $logger;

    /**
     * @param \Tagalys\Sync\Helper\Product
     */
    private $tagalysProduct;

    /**
     * @param \Tagalys\Sync\Helper\Category
     */
    private $tagalysCategoryHelper;

    /**
     * @param \Magento\Catalog\Model\ProductFactory
     */
    private $productFactory;

    /**
     * @param \Tagalys\Sync\Helper\Configuration
     */
    private $tagalysConfiguration;

    /**
     * @param \Tagalys\Sync\Helper\Sync
     */
    private $tagalysSync;

    private $haveSetTagalysContext = false;

    /**
     * @param \Magento\Framework\App\Config\ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @param \Tagalys\Sync\Helper\TagalysSql
     */
    private $tagalysSql;

    /**
     * @param \Tagalys\Sync\Helper\AuditLog
     */
    private $auditLogHelper;

    /**
     * @param \Tagalys\Sync\Helper\TableCrud
     */
    private $tableCrud;

    public function __construct(
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration,
        \Tagalys\Sync\Helper\Api $tagalysApi,
        \Tagalys\Sync\Helper\Sync $tagalysSync,
        \Tagalys\Sync\Helper\Category $tagalysCategoryHelper,
        \Tagalys\Sync\Helper\Queue $queueHelper,
        \Tagalys\Sync\Model\ConfigFactory $configFactory,
        \Tagalys\Sync\Model\CategoryFactory $tagalysCategoryFactory,
        \Magento\Framework\Filesystem $filesystem,
        \Tagalys\Sync\Helper\Product $tagalysProduct,
        \Magento\Framework\Registry $_registry,
        \Magento\Catalog\Model\ProductFactory $productFactory,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Tagalys\Sync\Helper\TagalysSql $tagalysSql,
        \Tagalys\Sync\Helper\AuditLog $auditLogHelper,
        \Tagalys\Sync\Helper\TableCrud $tableCrud
    ) {
        $this->tagalysConfiguration = $tagalysConfiguration;
        $this->tagalysApi = $tagalysApi;
        $this->tagalysSync = $tagalysSync;
        $this->tagalysCategoryHelper = $tagalysCategoryHelper;
        $this->queueHelper = $queueHelper;
        $this->configFactory = $configFactory;
        $this->tagalysCategoryFactory = $tagalysCategoryFactory;
        $this->filesystem = $filesystem;
        $this->tagalysProduct = $tagalysProduct;
        $this->_registry = $_registry;
        $this->productFactory = $productFactory;
        $this->scopeConfig = $scopeConfig;
        $this->tagalysSql = $tagalysSql;
        $this->auditLogHelper = $auditLogHelper;
        $this->tableCrud = $tableCrud;

        $logLevel = $this->tagalysConfiguration->getLogLevel();
        $this->logger = Utils::getLogger("tagalys_rest_api.log", $logLevel);
    }

    public function syncCallback($params) {
        $split = explode('media/tagalys/', $params['completed']);
        $filename = $split[1];
        if (is_null($filename)) {
            $split = explode('media\/tagalys\/', $params['completed']);
            $filename = $split[1];
        }
        if (is_null($filename)) {
            $this->tagalysApi->log('error', 'Error in callbackAction. Unable to read filename', array('params' => $params));
            $response = array('result' => false);
        } else {
            $this->tagalysSync->receivedCallback($params['store_id'], $filename);
            $response = array('result' => true);
        }
        return json_encode($response);
    }

    private function setTagalysContext() {
        if (!$this->haveSetTagalysContext) {
            $this->_registry->register("tagalys_context", true);
            $this->haveSetTagalysContext=true;
        }
    }

    public function info($params)
    {
        $this->setTagalysContext();
        $response = array('status' => 'error', 'message' => 'invalid info_type');
        try {
            switch ($params['info_type']) {
                case 'status':
                    $info = array(
                        'plugin_version' => $this->tagalysApi->getPluginVersion(),
                        'config' => [],
                        'files_in_media_folder' => array(),
                        'sync_status' => $this->tagalysSync->status()
                    );
                    $configCollection = $this->configFactory->create()->getCollection()->setOrder('id', 'ASC');
                    foreach ($configCollection as $i) {
                        $info['config'][$i->getData('path')] = $i->getData('value');
                    }
                    $this->tagalysSync->forEachFileInMediaFolder(function($path, $name) use (&$info) {
                        $info['files_in_media_folder'][] = $name;
                    });
                    $response = $info;
                    break;
                case 'get_config':
                    if (!array_key_exists('only_defaults', $params)) {
                        $params['only_defaults'] = false;
                    }
                    if (!array_key_exists('include_defaults', $params)) {
                        $params['include_defaults'] = true;
                    }
                    $response = [];
                    if($params['only_defaults']) {
                        $response = $this->tagalysConfiguration->defaultConfigValues;
                    } else {
                        if($params['include_defaults']) {
                            $response = $this->tagalysConfiguration->defaultConfigValues;
                        }
                        $configCollection = $this->configFactory->create()->getCollection()->setOrder('id', 'ASC');
                        foreach ($configCollection as $config) {
                            $response[$config->getPath()] = $config->getValue();
                        }
                    }
                    break;
                case 'product_details':
                    $productDetails = array();
                    if (array_key_exists('product_id', $params)) {
                        $params['product_ids'] = [$params['product_id']];
                    }
                    if (!array_key_exists('selective', $params)) {
                        $params['selective'] = false;
                    }
                    if (!array_key_exists('force_regenerate_thumbnail', $params)) {
                        $params['force_regenerate_thumbnail'] = false;
                    }
                    $stores = array_key_exists('stores', $params) ? $params['stores'] : $this->tagalysConfiguration->getStoresForTagalys();
                    foreach ($stores as $storeId) {
                        $productDetails['store-' . $storeId] = [];
                        foreach($params['product_ids'] as $pid) {
                            $product = $this->productFactory->create()->setStoreId($storeId)->load($pid);
                            if($params['selective']) {
                                $productDetailsForStore = (array) $this->tagalysProduct->getSelectiveProductDetails($storeId, $product);
                            } else {
                                $productDetailsForStore = (array) $this->tagalysProduct->productDetails($product, $storeId, $params['force_regenerate_thumbnail']);
                            }
                            $productDetails['store-' . $storeId][$pid] = $productDetailsForStore;
                        }
                    }
                    $response = $productDetails;
                    break;
                case 'reset_sync_statuses':
                    $this->queueHelper->deleteByPriority(0);
                    if (!array_key_exists('stores', $params)) {
                        $params['stores'] = $this->tagalysConfiguration->getStoresForTagalys();
                    }
                    foreach ($params['stores'] as $storeId) {
                        $sync_types = array('updates', 'feed');
                        foreach ($sync_types as $sync_type) {
                            $this->tagalysConfiguration->updateJsonConfig("store:$storeId:" . $sync_type . "_status", ['status' => 'finished', 'locked_by' => null, 'abandon' => true]);
                        }
                    }
                    $response = array('reset' => true);
                    break;
                case 'trigger_full_product_sync':
                    if (!array_key_exists('force_regenerate_thumbnails', $params)) {
                        $params['force_regenerate_thumbnails'] = false;
                    }
                    if (!array_key_exists('products_count', $params)) {
                        $params['products_count'] = false;
                    }
                    if (!array_key_exists('stores', $params)) {
                        $params['stores'] = $this->tagalysConfiguration->getStoresForTagalys();
                    }
                    $this->tagalysApi->log('warn', 'Triggering full products resync via API', array('force_regenerate_thumbnails' => ($params['force_regenerate_thumbnails'] == 'true')));
                    foreach ($params['stores'] as $storeId) {
                        if (isset($params['products_count'])) {
                            $this->tagalysSync->triggerFeedForStore($storeId, ($params['force_regenerate_thumbnails'] == 'true'), $params['products_count'], true);
                        } else {
                            $this->tagalysSync->triggerFeedForStore($storeId, ($params['force_regenerate_thumbnails'] == 'true'), false, true);
                        }
                        $this->queueHelper->deleteByPriority(0, $storeId);
                    }
                    $response = array('triggered' => true);
                    break;
                case 'trigger_quick_feed':
                    if (!array_key_exists('stores', $params)) {
                        $params['stores'] = $this->tagalysConfiguration->getStoresForTagalys();
                    }
                    $this->tagalysApi->log('warn', 'Triggering quick feed via API', array('stores' => $params['stores']));
                    $this->tagalysSync->triggerQuickFeed($params['stores']);
                    $response = array('triggered' => true);
                    break;
                case 'insert_into_sync_queue':
                    $this->tagalysApi->log('warn', 'Inserting into sync queue via API', array('product_ids' => $params['product_ids']));
                    $priority = array_key_exists('priority', $params) ? $params['priority'] : 0;
                    $stores = Utils::fetchKey($params, 'stores');
                    $insertPrimary = array_key_exists('insert_primary', $params) ? $params['insert_primary'] : null;
                    $includeDeleted = array_key_exists('include_deleted', $params) ? $params['include_deleted'] : null;
                    $res = $this->queueHelper->insertUnique($params['product_ids'], $priority, $stores, $insertPrimary, $includeDeleted);
                    $response = array('inserted' => true, 'info' => $res);
                    break;
                case 'truncate_sync_queue':
                    $this->tagalysApi->log('warn', 'Truncating sync queue via API');
                    $preserve_priority_items = array_key_exists('preserve_priority_items', $params) ? $params['preserve_priority_items'] : true;
                    $this->queueHelper->truncate($preserve_priority_items);
                    $response = array('truncated' => true);
                    break;
                case 'mark_positions_sync_required_for_categories':
                    $this->tagalysCategoryHelper->markPositionsSyncRequiredForCategories($params['store_id'], $params['category_ids']);
                    $response = array('updated' => true);
                    break;
                case 'get_categories_powered_by_tagalys':
                    $categories = array();
                    $tagalysCategoryCollection = $this->tagalysCategoryFactory->create()->getCollection()->setOrder('id', 'ASC');
                    foreach ($tagalysCategoryCollection as $i) {
                        $categoryData = $i->getData();
                        array_push($categories, $categoryData);
                    }
                    $response = array('categories' => $categories);
                    break;
                case 'update_tagalys_health_status':
                    if (isset($params['value']) && in_array($params['value'], array('1', '0'))) {
                        $this->tagalysConfiguration->setConfig("tagalys:health", $params['value']);
                    } else {
                        $this->tagalysConfiguration->updateTagalysHealth();
                    }
                    $response = array('health_status' => $this->tagalysConfiguration->getConfig("tagalys:health"));
                    break;
                case 'get_tagalys_health_status':
                    $response = array('health_status' => $this->tagalysConfiguration->getConfig("tagalys:health"));
                    break;
                case 'update_tagalys_plan_features':
                    $this->tagalysConfiguration->setConfig('tagalys_plan_features', $params['plan_features'], true);
                    $response = array('updated' => true);
                    break;
                case 'assign_products_to_category_and_remove':
                    $this->logger->info("assign_products_to_category_and_remove: params: " . json_encode($params));
                    $listingPagesEnabled = $this->tagalysConfiguration->isListingPagesEnabled();
                    $updatePositionAsync = $this->tagalysConfiguration->getConfig('listing_pages:update_position_async', true);
                    $updatePositionAsync = Utils::fetchKey($params, "update_products_async", $updatePositionAsync);
                    $this->tagalysCategoryHelper->markAsPositionSyncRequired($params['store_id'], $params['category_id'], true);
                    if ($listingPagesEnabled && !$updatePositionAsync) {
                        if ($params['product_positions'] == -1) {
                            $params['product_positions'] = [];
                        }
                        $this->tagalysCategoryHelper->bulkAssignProductsToCategoryAndRemove($params['store_id'], $params['category_id'], $params['product_positions']);
                        $async = false;
                    } else {
                        $async = true;
                    }
                    $response = [
                        'status' => 'OK',
                        'async' => $async,
                        'update_position_async' => $updatePositionAsync,
                        'listing_pages_enabled' => $listingPagesEnabled,
                    ];
                    break;
                case 'update_product_positions':
                    $this->logger->info("update_product_positions: params: " . json_encode($params));
                    $listingPagesEnabled = $this->tagalysConfiguration->isListingPagesEnabled();
                    $updatePositionAsync = $this->tagalysConfiguration->getConfig('listing_pages:update_position_async', true);
                    $updatePositionAsync = Utils::fetchKey($params, "update_products_async", $updatePositionAsync);
                    $this->tagalysCategoryHelper->markAsPositionSyncRequired($params['store_id'], $params['category_id'], false);
                    if($listingPagesEnabled && !$updatePositionAsync){
                        if ($params['product_positions'] == -1) {
                            $params['product_positions'] = [];
                        }
                        $this->tagalysCategoryHelper->performCategoryPositionUpdate($params['store_id'], $params['category_id'], $params['product_positions']);
                        $async = false;
                    } else {
                        $async = true;
                    }
                    $response = [
                        'status' => 'OK',
                        'async' => $async,
                        'update_position_async' => $updatePositionAsync,
                        'listing_pages_enabled' => $listingPagesEnabled,
                    ];
                    break;
                case 'clear_category_caches':
                    $this->logger->info("clear_category_caches: params: " . json_encode($params));
                    $this->tagalysCategoryHelper->clearCacheForCategories($params['category_ids']);
                    $response = ['status' => 'OK', 'message' => 'cleared'];
                    break;
                case 'reindex_categories':
                    $this->logger->info("clear_category_caches: params: " . json_encode($params));
                    $res = $this->tagalysCategoryHelper->reindexCategoryProducts($params['category_ids'], '', true);
                    $response = ['status' => 'OK', 'reindexed' => $res];
                    break;
                case 'get_plugin_version':
                    $response = ['status' => 'OK', 'plugin_version' => $this->tagalysApi->getPluginVersion()];
                    break;
                case 'ping':
                    $response = ['status' => 'OK', 'message' => 'pong'];
                    break;
                case 'get_tagalys_logs':
                    if (empty($params['lines'])) {
                        $params['lines'] = 10;
                    }
                    ob_start();
                    passthru('tail -n' . escapeshellarg($params['lines']) . ' var/log/tagalys_' . escapeshellarg($params['file']) . '.log');
                    $response = ['status' => 'OK', 'message' => explode("\n", trim(ob_get_clean()))];
                    break;
                case 'update_category_pages_store_mapping':
                    $this->tagalysConfiguration->setConfig('category_pages_store_mapping', $params['store_mapping'], true);
                    $response = array('updated' => true, $params['store_mapping']);
                    break;
                case 'update_product_update_detection_methods':
                    $this->tagalysConfiguration->setConfig('product_update_detection_methods', $params['methods'], true);
                    $response = array('updated' => true, $params['methods']);
                    break;
                case 'set_config':
                    if (!array_key_exists('json_encode', $params)){
                        $params['json_encode'] = false;
                    }
                    $this->tagalysConfiguration->setConfig($params['path'], $params['value'], $params['json_encode']);
                    $response = array(
                        'updated' => true,
                        'db_value' => $this->tagalysConfiguration->getConfig($params['path']),
                        $params['value'],
                    );
                    break;
                case 'update_config':
                    $this->tagalysConfiguration->updateJsonConfig($params['path'], $params['value']);
                    $response = array('updated' => true, $params['value']);
                    break;
                case 'get_order_data':
                    $res = $this->tagalysSync->getOrderData($params['store_id'], $params['from'], $params['to']);
                    $response = array('status' => 'OK', 'message' => $res);
                    break;
                case 'update_tagalys_category_table':
                    $rows = [];
                    if (array_key_exists('rows', $params)) {
                        $rows = $params['rows'];
                    } else {
                        $rows[] = [
                            'store_id' => $params['store_id'],
                            'category_id' => $params['category_id'],
                            'data' => $params['data'],
                        ];
                    }
                    $this->tagalysCategoryHelper->createOrUpdateWithRows($rows);
                    $response = array('status' => 'OK', 'updated' => true);
                    break;
                case 'get_tagalys_queue':
                    $productIds = $this->queueHelper->getProductsInQueueForAPI();
                    $response = array('status' => 'OK', 'products' => $productIds);
                    break;
                case 'remove_from_tagalys_queue':
                    if (array_key_exists('product_ids', $params)){
                        $priority = array_key_exists('priority', $params) ? $params['priority'] : null;
                        $stores = array_key_exists('stores', $params) ? $params['stores'] : null;
                        $res = $this->queueHelper->paginateSqlDelete($params['product_ids'], $priority, $stores);
                    } else {
                        $res = false;
                    }
                    $response = array('status' => 'OK', 'removed' => $res);
                    break;
                case 'remove_duplicates_from_tagalys_queue':
                    $response = $this->queueHelper->removeDuplicatesFromQueue();
                    break;
                case 'get_relevant_product_ids':
                    $includeDeleted = array_key_exists('include_deleted', $params) ? $params['include_deleted'] : false;
                    $response = $this->queueHelper->getRelevantProductIds($params['product_ids'], $includeDeleted);
                    break;
                case 'delete_from_tagalys_queue_by_priority':
                    if (array_key_exists('priority', $params)){
                        $priority = $params['priority'];
                        $this->queueHelper->deleteByPriority($priority);
                        $response = ['deleted' => true, 'message' => "deleted rows with priority $priority"];
                    } else {
                        $response = ['message' => 'required param `priority` is missing'];
                    }
                    break;
                case 'get_positions':
                    $positions = $this->tagalysCategoryHelper->getProductPosition($params['category_id']);
                    $indexPositions = $this->tagalysCategoryHelper->getProductPositionFromIndex($params['store_id'], $params['category_id']);
                    $response = array('status' => 'OK', 'positions' => $positions, 'index_positions' => $indexPositions);
                    break;
                case 'trigger_category_sync':
                    if (!array_key_exists('store_id', $params)) {
                        $params['store_id'] = false;
                    }
                    $res = $this->tagalysCategoryHelper->triggerCategorySync($params['store_id']);
                    $response = ['status' => 'OK', 'updated' => $res];
                    break;
                case 'get_visible_attributes':
                case 'get_visible_attribute':
                    $response = [
                        'status' => 'OK',
                        'attributes' => $this->tagalysConfiguration->getAllVisibleAttributesForAPI()
                    ];
                    break;
                case 'get_all_stores':
                    $response = [
                        'status' => 'OK',
                        'stores' => $this->tagalysConfiguration->getAllStoresForAPI()
                    ];
                    break;
                case 'get_all_categories':
                    if(!array_key_exists('include_tagalys_created', $params)) {
                        $params['include_tagalys_created'] = false;
                    }
                    if(!array_key_exists('process_ancestry', $params)) {
                        $params['process_ancestry'] = false;
                    }
                    $response = [
                        'status' => 'OK',
                        'categories' => $this->tagalysConfiguration->getAllCategoriesForAPI($params['store_id'], $params['include_tagalys_created'], $params['process_ancestry'])
                    ];
                    break;
                case 'get_bool_attr_value':
                    $response = [
                        'status' => 'OK',
                        'values' => $this->tagalysProduct->getBooleanAttrValueForAPI($params['store_id'], $params['product_id'])
                    ];
                    break;
                case 'get_id_by_sku':
                    $response = [
                        'status' => 'OK',
                        'ids' => $this->tagalysProduct->getIdsBySku($params['skus'])
                    ];
                    break;
                case 'delete_sync_files':
                    $deletedAllFiles = (array_key_exists('delete_all', $params) && !!$params['delete_all']);
                    if($deletedAllFiles) {
                        $res = $this->tagalysSync->deleteAllSyncFiles();
                    } else {
                        $res = $this->tagalysSync->deleteSyncFiles($params['files']);
                    }
                    $response = ['status' => 'OK', 'deleted' => $res];
                    break;
                case 'bulk_ops':
                    $response = ['results' => [] ];
                    foreach($params['ops'] as $opParams) {
                        if($opParams['info_type'] == 'bulk_ops') {
                            $response = ['results' => false, 'message' => "info_type: bulk_ops not permitted as part of ops."];
                            break;
                        }
                        $opResponse = json_decode($this->info($opParams));
                        $response['results'][] = $opResponse;
                    }
                    break;
                case 'get_products_to_remove':
                    $productIds = $params['product_ids'];
                    $storeId = $params['store_id'];
                    $idsToRemove = $this->tagalysSync->getProductIdsToRemove($storeId, $productIds);
                    $response = ['status' => 'OK', 'to_remove' => $idsToRemove];
                    break;
                default:
                    $method = Utils::camelize($params['info_type']);
                    $whitelistedMethodNames = [
                        "syncProducts",
                        "syncCategories",
                        "syncPositions",
                        "deleteAuditLogs",
                        "getAuditLogs",
                        'getStoreConfiguration',
                        'getStoreCategoryDetails',
                        'getCatalogProductEntities',
                        'deleteTagalysCategoryEntries',
                        'clearConfig',
                    ];
                    if(in_array($method, $whitelistedMethodNames)) {
                        $response = $this->{$method}($params);
                    }
            }
        } catch (\Exception $e) {
            $response = ['status' => 'error', 'message' => $e->getMessage(), 'trace' => $e->getTrace()];
        }
        return json_encode($response);
    }

    public function categorySave($category) {
        try {
            $this->logger->info("categorySave: params: " . json_encode($category));
            if ($category['id']){
                // update mode
                $categoryId = $this->tagalysCategoryHelper->updateCategoryDetails($category['id'], $category['details'], $category['for_stores']);
            } else {
                // create mode
                $categoryId = $this->tagalysCategoryHelper->createCategory($category['details'], $category['for_stores']);
            }
            $response = ['status' => 'OK', 'id' => $categoryId];
        } catch (\Exception $e) {
            $response = ['status' => 'error', 'message' => $e->getMessage(), 'trace' => $e->getTrace()];
        }
        // FIXME: use Api Data Interface
        return json_encode($response);
    }

    public function categoryDelete($categoryId) {
        try {
            $this->logger->info("categoryTryDelete: categoryId: $categoryId");
            if ($this->tagalysCategoryHelper->categoryExist($categoryId)){
                $this->tagalysCategoryHelper->deleteTagalysCategory($categoryId);
            }
            $response = ['status' => 'OK', 'deleted' => true];
        } catch (\Exception $e) {
            $response = ['status' => 'error', 'message' => $e->getMessage(), 'trace' => $e->getTrace()];
        }
        return json_encode($response);
    }

    public function categoryDisable($storeIds, $categoryId){
        try {
            $this->logger->info("categoryTryDelete: params: " . json_encode(['storeIds' => $storeIds, 'categoryId' => $categoryId]));
            if ($this->tagalysCategoryHelper->categoryExist($categoryId)) {
                $this->tagalysCategoryHelper->updateCategoryDetails($categoryId, ['is_active' => false], $storeIds);
                $response = ['status' => 'OK', 'disabled' => true, 'found' => true];
            } else {
                $response = ['status' => 'OK', 'disabled' => true, 'found' => false];
            }
        } catch (\Exception $e) {
            $response = ['status' => 'error', 'message' => $e->getMessage(), 'trace' => $e->getTrace()];
        }
        return json_encode($response);
    }

    public function getCronSchedule($params) {
        $response = ['configuration' => []];
        $response['magento_cron_enabled'] = $this->tagalysConfiguration->getConfig('magento_cron_enabled', true);
        $response['configuration']['sync'] = $this->scopeConfig->getValue('tagalys_cron/sync/cron_expr');
        $response['configuration']['position_update'] = $this->scopeConfig->getValue('tagalys_cron/position_update/cron_expr');
        $response['configuration']['maintenance'] = $this->scopeConfig->getValue('tagalys_cron/maintenance/cron_expr');
        $cronScheduleTable = $this->tagalysSql->getTableName("cron_schedule");
        $response['entries'] = $this->tagalysSql->runSqlSelect("SELECT * FROM $cronScheduleTable WHERE job_code LIKE 'Tagalys%' ORDER BY scheduled_at DESC LIMIT 1000;");
        return $response;
    }

    public function syncProducts($params){
        if (empty($params['count'])) {
            $params['count'] = 10;
        }
        $count = (int) $params['count'];
        if($count > 0) {
            $this->tagalysSync->sync($count);
            return "synced {$params['count']} products";
        }
        return false;
    }

    public function syncCategories($params){
        if(empty($params['count'])) {
            $params['count'] = 10;
        }
        $count = (int) $params['count'];
        if($count > 0) {
            $this->tagalysCategoryHelper->sync($count);
            return "synced {$params['count']} categories";
        }
        return false;
    }

    public function syncPositions($params) {
        $count = (int) Utils::fetchKey($params, 'count', 1);
        $this->tagalysCategoryHelper->updatePositionsIfRequired($count);
        return "synced positions for {$count} categories";
    }

    public function deleteAuditLogs($params) {
        if (Utils::fetchKey($params, 'clear_all', false)) {
            $this->auditLogHelper->truncate();
        } else {
            $this->auditLogHelper->deleteLogEntries($params['from'], $params['to']);
        }
        return ['deleted' => true];
    }

    public function getAuditLogs($params) {
        if(Utils::fetchKey($params, 'ids_only', false)) {
            $output = $this->auditLogHelper->getAllIds();
        } else {
            $output = $this->auditLogHelper->getEntries($params['ids']);
        }
        return $output;
    }

    public function getStoreConfiguration($params) {
        return $this->tagalysConfiguration->getStoreConfiguration($params['store_id']);
    }

    public function getStoreCategoryDetails($params) {
        return $this->tagalysCategoryHelper->getStoreCategoryDetails($params['store_id'], $params['category_id']);
    }

    public function getCatalogProductEntities($params) {
        $where = null;
        $limit = null;
        if(isset($params['product_ids'])) {
            $where = [
                "entity_id IN (?)",
                $params['product_ids']
            ];
        } else {
            $limit = Utils::fetchKey($params, 'limit', 10);
        }
        return $this->tableCrud->select('catalog_product_entity', $where, "updated_at DESC", $limit);
    }

    public function deleteTagalysCategoryEntries($params) {
        foreach ($params['category_ids'] as $categoryId) {
            $this->tagalysCategoryHelper->deleteCategoryEntries($params['store_id'], $categoryId);
        }
        return ['deleted' => true];
    }

    public function clearConfig($params) {
        $value = $this->tagalysConfiguration->clearConfig($params['path']);
        return ['cleared' => true, 'value' => $value];
    }

}
