<?php

namespace Tagalys\Sync\Model;

use Tagalys\Sync\Api\TagalysManagementInterface;

class TagalysApi implements TagalysManagementInterface
{

    /**
     * @param \Tagalys\Sync\Helper\Product
     */
    private $tagalysProduct;

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
        \Magento\Framework\Registry $_registry
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

        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/tagalys_rest_api.log');
        $this->logger = new \Zend\Log\Logger();
        $this->logger->addWriter($writer);
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

    public function info($params)
    {
        $this->_registry->register("tagalys_context", true);
        $response = array('status' => 'error', 'message' => 'invalid info_type');
        try {
            switch ($params['info_type']) {
                case 'status':
                    $info = array('config' => array(), 'files_in_media_folder' => array(), 'sync_status' => $this->tagalysSync->status());
                    $configCollection = $this->configFactory->create()->getCollection()->setOrder('id', 'ASC');
                    foreach ($configCollection as $i) {
                        $info['config'][$i->getData('path')] = $i->getData('value');
                    }
                    $mediaDirectory = $this->filesystem->getDirectoryRead('media')->getAbsolutePath('tagalys');
                    $filesInMediaDirectory = scandir($mediaDirectory);
                    foreach ($filesInMediaDirectory as $key => $value) {
                        if (!is_dir($mediaDirectory . DIRECTORY_SEPARATOR . $value)) {
                            if (!preg_match("/^\./", $value)) {
                                $info['files_in_media_folder'][] = $value;
                            }
                        }
                    }
                    $response = $info;
                    break;
                case 'product_details':
                    $productDetails = array();
                    if (array_key_exists('product_id', $params)) {
                        $params['product_ids'] = [$params['product_id']];
                    }
                    $stores = array_key_exists('stores', $params) ? $params['stores'] : $this->tagalysConfiguration->getStoresForTagalys();
                    foreach ($stores as $storeId) {
                        $productDetails['store-' . $storeId] = [];
                        foreach($params['product_ids'] as $pid) {
                            $productDetailsForStore = (array) $this->tagalysProduct->productDetails($pid, $storeId);
                            $productDetails['store-' . $storeId][$pid] = $productDetailsForStore;
                        }
                    }
                    $response = $productDetails;
                    break;
                case 'reset_sync_statuses':
                    $this->queueHelper->truncate();
                    foreach ($this->tagalysConfiguration->getStoresForTagalys() as $storeId) {
                        $sync_types = array('updates', 'feed');
                        foreach ($sync_types as $sync_type) {
                            $syncTypeStatus = $this->tagalysConfiguration->getConfig("store:$storeId:" . $sync_type . "_status", true);
                            $syncTypeStatus['status'] = 'finished';
                            $feed_status = $this->tagalysConfiguration->setConfig("store:$storeId:" . $sync_type . "_status", json_encode($syncTypeStatus));
                        }
                    }
                    $response = array('reset' => true);
                    break;
                case 'trigger_full_product_sync':
                    $this->tagalysApi->log('warn', 'Triggering full products resync via API', array('force_regenerate_thumbnails' => ($params['force_regenerate_thumbnails'] == 'true')));
                    foreach ($this->tagalysConfiguration->getStoresForTagalys() as $storeId) {
                        if (isset($params['products_count'])) {
                            $this->tagalysSync->triggerFeedForStore($storeId, ($params['force_regenerate_thumbnails'] == 'true'), $params['products_count'], true);
                        } else {
                            $this->tagalysSync->triggerFeedForStore($storeId, ($params['force_regenerate_thumbnails'] == 'true'), false, true);
                        }
                    }
                    $this->queueHelper->truncate();
                    $response = array('triggered' => true);
                    break;
                case 'insert_into_sync_queue':
                    $this->tagalysApi->log('warn', 'Inserting into sync queue via API', array('product_ids' => $params['product_ids']));
                    $this->queueHelper->insertUnique($params['product_ids']);
                    $response = array('inserted' => true);
                    break;
                case 'truncate_sync_queue':
                    $this->tagalysApi->log('warn', 'Truncating sync queue via API');
                    $this->queueHelper->truncate();
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
                        $fields = array('id', 'category_id', 'store_id', 'positions_synced_at', 'positions_sync_required', 'marked_for_deletion', 'status');
                        $categoryData = array();
                        foreach ($fields as $field) {
                            $categoryData[$field] = $i->getData($field);
                        }
                        array_push($categories, $categoryData);
                    }
                    $response = array('categories' => $categories);
                    break;
                case 'update_tagalys_health_status':
                    if (isset($params['value']) && in_array($params['value'], array('1', '0'))) {
                        $this->tagalysConfiguration->setConfig("tagalys:health", $params['value']);
                    } else {
                        $this->tagalysSync->updateTagalysHealth();
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
                    $async = $this->tagalysConfiguration->getConfig('listing_pages:update_position_async', true);
                    $res = $this->tagalysCategoryHelper->updateWithData($params['store_id'], $params['category_id'], ['positions_sync_required' => 1]);
                    if (!$async) {
                        if ($params['product_positions'] == -1) {
                            $params['product_positions'] = [];
                        }
                        $res = $this->tagalysCategoryHelper->bulkAssignProductsToCategoryAndRemove($params['store_id'], $params['category_id'], $params['product_positions']);
                    }
                    if ($res) {
                        $response = ['status' => 'OK', 'message' => $res, 'async' => $async];
                    } else {
                        $response = ['status' => 'error', 'message' => 'Unknown error occurred'];
                    }
                    break;
                case 'update_product_positions':
                    $this->logger->info("update_product_positions: params: " . json_encode($params));
                    $async = $this->tagalysConfiguration->getConfig('listing_pages:update_position_async', true);
                    $this->tagalysCategoryHelper->updateWithData($params['store_id'], $params['category_id'], ['positions_sync_required' => 1]);
                    if(!$async){
                        if ($params['product_positions'] == -1) {
                            $params['product_positions'] = [];
                        }
                        $this->tagalysCategoryHelper->performCategoryPositionUpdate($params['store_id'], $params['category_id'], $params['product_positions']);
                    }
                    $response = ['status' => 'OK', 'message' => 'updated', 'async' => $async];
                    break;
                case 'clear_category_caches':
                    $this->logger->info("clear_category_caches: params: " . json_encode($params));
                    $this->tagalysCategoryHelper->clearCacheForCategories($params['category_ids']);
                    $response = ['status' => 'OK', 'message' => 'cleared'];
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
                    $productIds = $this->queueHelper->getProductsInQueue();
                    $response = array('status' => 'OK', 'products' => $productIds);
                    break;
                case 'remove_from_tagalys_queue':
                    $res = $this->queueHelper->removeProductIdsIn($params['product_ids']);
                    $response = array('status' => 'OK', 'removed' => $res);
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
                        'stores' => $this->tagalysConfiguration->getAllCategoriesForAPI($params['store_id'], $params['include_tagalys_created'], $params['process_ancestry'])
                    ];
                    break;
                case 'get_bool_attr_value':
                    $response = [
                        'status' => 'OK',
                        'values' => $this->tagalysProduct->getBooleanAttrValueForAPI($params['store_id'], $params['product_id'])
                    ];
                    break;
            }
        } catch (\Exception $e) {
            $response = ['status' => 'error', 'message' => $e->getMessage(), 'trace' => $e->getTrace()];
        }
        return json_encode($response);
    }

    public function categorySave($category) {
        // ALERT: Test this in 2.0 - 2.1
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
}
