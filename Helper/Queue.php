<?php
namespace Tagalys\Sync\Helper;

class Queue extends \Magento\Framework\App\Helper\AbstractHelper
{

    private $tableName;
    private $visibilityAttrId = null;
    private $sqlBulkBatchSize = 1000;

    public function __construct(
        \Tagalys\Sync\Model\QueueFactory $queueFactory,
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\ConfigurableProduct\Model\Product\Type\Configurable $configurableProduct,
        \Magento\Catalog\Model\ProductFactory $productFactory,
        \Magento\Framework\App\ResourceConnection $resourceConnection,
        \Magento\Framework\App\ProductMetadataInterface $productMetadataInterface,
        \Tagalys\Sync\Helper\Api $tagalysApi
    )
    {
        $this->queueFactory = $queueFactory;
        $this->tagalysConfiguration = $tagalysConfiguration;
        $this->storeManager = $storeManager;
        $this->configurableProduct = $configurableProduct;
        $this->productFactory = $productFactory;
        $this->resourceConnection = $resourceConnection;
        $this->productMetadataInterface = $productMetadataInterface;
        $this->tagalysApi = $tagalysApi;

        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/tagalys_core.log');
        $this->tagalysLogger = new \Zend\Log\Logger();
        $this->tagalysLogger->addWriter($writer);

        $this->tableName = $this->resourceConnection->getTableName('tagalys_queue');
    }

    public function insertUnique($productIds, $priority = 0, $insertPrimary = null, $includeDeleted = null) {
        $useOldInsertUnique = $this->tagalysConfiguration->getConfig('fallback:use_old_insert_unique', true);
        if ($useOldInsertUnique) {
            return $this->oldInsertUnique($productIds, $priority);
        } else {
            return $this->newInsertUnique($productIds, $priority, $insertPrimary, $includeDeleted);
        }
    }

    public function oldInsertUnique($productIds, $priority = 0) {
        try {
            if (!is_array($productIds)) {
                $productIds = array($productIds);
            }
            $logInsert = $this->tagalysConfiguration->getConfig('sync:log_product_ids_during_insert_to_queue', true);
            if($logInsert){
                $this->tagalysLogger->info("insertUnique: ProductIds: ". json_encode($productIds));
            }
            $insertPrimary = $this->tagalysConfiguration->getConfig('sync:insert_primary_products_in_insert_unique', true);
            $perPage = 100;
            $offset = 0;
            $queueTable = $this->resourceConnection->getTableName('tagalys_queue');
            $productIds = array_filter($productIds); // remove null values - this will cause a crash when used in the replace command below
            $productsToInsert = array_slice($productIds, $offset, $perPage);
            while(count($productsToInsert) > 0){
                if($insertPrimary){
                    $this->insertPrimaryProducts($productsToInsert, $priority);
                } else {
                    $productsToInsert = array_map(function($productId) use ($priority) {
                        return "($productId, $priority)";
                    }, $productsToInsert);
                    $values = implode(',', $productsToInsert);
                    $sql = "REPLACE $queueTable (product_id, priority) VALUES $values;";
                    $this->runSql($sql);
                }
                $offset += $perPage;
                $productsToInsert = array_slice($productIds, $offset, $perPage);
            }
        } catch (\Exception $e){
            $this->tagalysLogger->warn("insertUnique exception: " . json_encode(['message' => $e->getMessage(), 'product_ids' => $productIds, 'backtrace' => $e->getTrace()]));
        }
    }

    public function newInsertUnique($productIds, $priority = 0, $insertPrimary = null, $includeDeleted = null) {
        $productIds = is_array($productIds) ? $productIds : [$productIds];

        $response = [
            'input_count' => count($productIds),
            'insert_primary' => $insertPrimary,
            'include_deleted' => $includeDeleted,
            'count_after_filter' => null,
            'inserted' => 0,
            'updated' => 0,
            'ignored' => 0,
            'errors' => false
        ];

        try {
            // remove null values - this will cause a crash when used in the replace command below
            $productIds = array_filter($productIds);

            $logInsert = $this->tagalysConfiguration->getConfig('sync:log_product_ids_during_insert_to_queue', true);
            if($logInsert){
                $this->tagalysLogger->info("insertUnique: ProductIds: ". json_encode($productIds));
            }

            if (is_null($insertPrimary)){
                $insertPrimary = $this->tagalysConfiguration->getConfig('sync:insert_primary_products_in_insert_unique', true);
                $response['insert_primary'] = $insertPrimary;
            }
            if($insertPrimary) {
                if (is_null($includeDeleted)){
                    $includeDeleted = $this->tagalysConfiguration->getConfig('sync:include_deleted_products_in_insert_primary', true);
                    $response['include_deleted'] = $includeDeleted;
                }
                $relevantProductIds = $this->getRelevantProductIds($productIds, $includeDeleted);
            } else {
                $relevantProductIds = array_unique($productIds);
            }

            $response['count_after_filter'] = count($relevantProductIds);
            if(count($relevantProductIds) == 0) {
                return $response;
            }

            $idsByOperation = $this->splitProductIdsByOperations($relevantProductIds, $priority);
            $response['ignored'] += count($idsByOperation['ignore']);

            $this->paginateSqlInsert($idsByOperation['insert'], $priority);
            $response['inserted'] += count($idsByOperation['insert']);

            $this->paginateSqlUpdate($idsByOperation['update'], $priority);
            $response['updated'] += count($idsByOperation['update']);

            return $response;
        } catch (\Exception $e){
            $response['errors'] = true;
            $this->tagalysLogger->warn("insertUnique exception: " . json_encode(['message' => $e->getMessage(), 'product_ids' => $productIds, 'response' => $response, 'backtrace' => $e->getTrace()]));
            return $response;
        }
    }

    public function splitProductIdsByOperations($productIds, $priority) {
        $idsByOperation = [ 'insert' => [], 'update' => [], 'ignore' => [] ];
        Utils::forEachChunk($productIds, $this->sqlBulkBatchSize, function($idsChunk) use ($priority, &$idsByOperation) {
            $values = implode(',', $idsChunk);
            $sql = "SELECT product_id, priority FROM {$this->tableName} WHERE product_id IN ($values)";
            $rows = $this->runSqlSelect($sql);
            $productIdsToIgnore = [];
            $productIdsToUpdate = [];
            foreach($rows as $row) {
                if ($row['priority'] >= $priority) {
                    $productIdsToIgnore[] = $row['product_id'];
                } else {
                    $productIdsToUpdate[] = $row['product_id'];
                }
            }
            $productIdsToInsert = array_diff($idsChunk, $productIdsToIgnore, $productIdsToUpdate);

            $idsByOperation['insert'] = array_merge($idsByOperation['insert'], $productIdsToInsert);
            $idsByOperation['update'] = array_merge($idsByOperation['update'], $productIdsToUpdate);
            $idsByOperation['ignore'] = array_merge($idsByOperation['ignore'], $productIdsToIgnore);
        });
        return $idsByOperation;
    }

    public function getRelevantProductIds($productIds, $includeDeleted = true) {
        $productIdsToInsert = [];
        Utils::forEachChunk($productIds, $this->sqlBulkBatchSize, function($idsChunk) use (&$productIdsToInsert, $includeDeleted) {
            $primaryProductIds = $this->getPrimaryProductIds($idsChunk);
            $productIdsToInsert = array_merge($productIdsToInsert, $primaryProductIds);
            if($includeDeleted) {
                $deletedIds = $this->getDeletedProductIds($idsChunk);
                $productIdsToInsert = array_merge($productIdsToInsert, $deletedIds);
            }
        });
        return array_unique($productIdsToInsert);
    }

    /*
        Note:
            1. Call this function only through pagination. Will lead to SQL error if count($productIds) in large.
            2. Will not return deleted product ids
    */
    public function getPrimaryProductIds($productIds) {
        $parentProductIds = $this->getParentProductIds($productIds);
        return $this->getProductsVisibleInStores(array_merge($productIds, $parentProductIds));
    }

    public function getParentProductIds($productIds) {
        $parentIds = [];

        $cpr = $this->resourceConnection->getTableName('catalog_product_relation');
        $values = implode(',', $productIds);

        // select parent products of associated child products in the given array
        $sql = "SELECT DISTINCT cpr.parent_id as product_id FROM $cpr as cpr WHERE cpr.child_id IN ($values);";
        $rows = $this->runSqlSelect($sql);
        foreach($rows as $row) {
            $parentIds[] = $row['product_id'];
        }
        return $parentIds;
    }

    public function getProductsVisibleInStores($productIds, $stores = null) {
        $visibleProductIds = [];

        if(is_null($stores)) {
            $stores = $this->tagalysConfiguration->getStoresForTagalys(true);
            $stores = implode(',', $stores);
        }

        $cpe = $this->resourceConnection->getTableName('catalog_product_entity');
        $cpei = $this->resourceConnection->getTableName('catalog_product_entity_int');
        $columnToJoin = $this->getResourceColumnToJoin();
        $visibilityAttr = $this->getProductVisibilityAttrId();
        $values = implode(',', $productIds);

        $sql = "SELECT DISTINCT cpe.entity_id as product_id FROM $cpe as cpe INNER JOIN $cpei as cpei ON cpe.{$columnToJoin} = cpei.{$columnToJoin} WHERE cpe.entity_id IN ($values) AND cpei.attribute_id = $visibilityAttr AND cpei.value IN (2,3,4) AND cpei.store_id IN ($stores);";
        $rows = $this->runSqlSelect($sql);
        foreach($rows as $row) {
            $visibleProductIds[] = $row['product_id'];
        }
        return $visibleProductIds;
    }

    public function getDeletedProductIds($productIds) {
        $values = implode(',', $productIds);
        $cpe = $this->resourceConnection->getTableName('catalog_product_entity');
        $sql = "SELECT entity_id FROM $cpe WHERE entity_id IN ($values)";
        $rows = $this->runSqlSelect($sql);
        $productsInSystem = [];
        foreach($rows as $row) {
            $productsInSystem[] = $row['entity_id'];
        }
        return array_diff($productIds, $productsInSystem);
    }

    // To avoid using direct SQL from observers. Just in case.
    // Todo: rename to insertIfRequiredWithoutSql
    public function insertIfRequired($productIds){
        if (!is_array($productIds)) {
            $productIds = [$productIds];
        }
        foreach ($productIds as $productId) {
            $queueItem = $this->queueFactory->create()->getCollection()->addFieldToFilter('product_id', $productId)->getFirstItem();
            if (!$queueItem->getId()){
                $queueItem = $this->queueFactory->create();
            }
            $queueItem->setProductId($productId)->save();
        }
    }

    // rename to insertRecentlyUpdatedProductsToSync
    public function importProductsToSync() {
        // force UTC timezone
        $conn = $this->resourceConnection->getConnection();
        $conn->query("SET time_zone = '+00:00'");

        $tq = $this->resourceConnection->getTableName('tagalys_queue');
        $cpe = $this->resourceConnection->getTableName('catalog_product_entity');
        $lastDetected = $this->tagalysConfiguration->getConfig("sync:method:db.catalog_product_entity.updated_at:last_detected_change");
        if ($lastDetected == NULL) {
            $lastDetected = $this->runSqlSelect("SELECT updated_at from $cpe ORDER BY updated_at DESC LIMIT 1")[0]['updated_at'];
        }
        $optimize = $this->tagalysConfiguration->getConfig('use_optimized_product_updated_at', true);
        if ($optimize) {
            $stores = $this->tagalysConfiguration->getStoresForTagalys(true);
            $stores = implode(',', $stores);
            $cpei = $this->resourceConnection->getTableName('catalog_product_entity_int');
            $cpr = $this->resourceConnection->getTableName('catalog_product_relation');
            $attrId = $this->getProductVisibilityAttrId();
            $columnToMap = $this->getResourceColumnToJoin();
            // insert individually visible products
            $sql = "REPLACE $tq (product_id) SELECT DISTINCT cpe.entity_id as product_id FROM $cpe as cpe INNER JOIN $cpei as cpei ON cpe.{$columnToMap} = cpei.{$columnToMap} WHERE cpe.updated_at > '$lastDetected' AND cpei.attribute_id = $attrId AND cpei.value IN (2,3,4) AND cpei.store_id IN ($stores);";
            $this->runSql($sql);
            // insert parent products of associated child products
            $sql = "REPLACE $tq (product_id) SELECT DISTINCT cpr.parent_id as product_id FROM $cpr as cpr INNER JOIN $cpe as cpe ON cpe.entity_id = cpr.child_id WHERE cpe.updated_at > '$lastDetected'";
            $this->runSql($sql);
        } else {
            $sql = "REPLACE $tq (product_id) SELECT DISTINCT entity_id from $cpe WHERE updated_at > '$lastDetected'";
            $this->runSql($sql);
        }
        $lastDetected = $this->runSqlSelect("SELECT updated_at from $cpe ORDER BY updated_at DESC LIMIT 1")[0]['updated_at'];
        $this->tagalysConfiguration->setConfig("sync:method:db.catalog_product_entity.updated_at:last_detected_change", $lastDetected);
        return true;
    }

    private function runSql($sql){
        $conn = $this->resourceConnection->getConnection();
        $conn->query("SET time_zone = '+00:00'");
        return $conn->query($sql);
    }

    private function runSqlSelect($sql){
        $conn = $this->resourceConnection->getConnection();
        $conn->query("SET time_zone = '+00:00'");
        return $conn->fetchAll($sql);
    }

    public function truncateIfEmpty() {
        $queue = $this->queueFactory->create();
        $count = $queue->getCollection()->getSize();
        if ($count == 0) {
            $this->truncate();
        }
    }

    // Todo: rename this function, it's not just truncating anymore
    public function truncate($preserve_priority_items = true) {
        if ($preserve_priority_items) {
            $priorityRows = $this->getPriorityRows();
        }
        $queue = $this->queueFactory->create();
        $connection = $queue->getResource()->getConnection();
        $tableName = $queue->getResource()->getMainTable();
        $connection->truncateTable($tableName);
        if ($preserve_priority_items) {
            $this->paginateAndInsertRows($priorityRows);
        }
    }

    public function getPriorityRows() {
        $collection = $this->queueFactory->create()->getCollection()->addFieldToFilter('priority', ['gt' => 0])->setOrder('priority', 'desc');
        $productIds = array_map(function($item){
            $productId = $item['product_id'];
            $priority = $item['priority'];
            return "($productId, $priority)";
        }, $collection->toArray(['product_id', 'priority'])['items']);
        return $productIds;
    }

    public function paginateAndInsertRows($rows) {
        $queueTable = $this->resourceConnection->getTableName('tagalys_queue');
        Utils::forEachChunk($rows, $this->sqlBulkBatchSize, function($rowsToInsert) use ($queueTable){
            $values = implode(',', $rowsToInsert);
            $sql = "REPLACE $queueTable (product_id, priority) VALUES $values;";
            $this->runSql($sql);
        });
    }

    public function queuePrimaryProductIdFor($productId) {
        $primaryProductId = $this->getPrimaryProductId($productId);
        if ($primaryProductId === false) {
            // no related product id
        } elseif ($productId == $primaryProductId) {
            // same product. so no related product id.
        } else {
            // add primaryProductId and remove productId
            $this->insertUnique($primaryProductId);
        }
        return $primaryProductId;
    }

    // Call this function only through pagination. Will lead to SQL error if count($productIds) in large.
    public function insertPrimaryProducts($productIds, $priority = 0){
        $productIds = implode(',', $productIds);
        $tagalysStores = $this->tagalysConfiguration->getStoresForTagalys(true);
        $tagalysStores = implode(',', $tagalysStores);
        $tq = $this->resourceConnection->getTableName('tagalys_queue');
        $cpe = $this->resourceConnection->getTableName('catalog_product_entity');
        $cpei = $this->resourceConnection->getTableName('catalog_product_entity_int');
        $cpr = $this->resourceConnection->getTableName('catalog_product_relation');
        // find the attribute id for product visibility
        $visibilityAttr = $this->getProductVisibilityAttrId();
        $columnToJoin = $this->getResourceColumnToJoin();
        // insert all individually visible products from the given array
        $sql = "REPLACE $tq (product_id, priority) SELECT DISTINCT cpe.entity_id, $priority FROM $cpe as cpe INNER JOIN $cpei as cpei ON cpe.{$columnToJoin} = cpei.{$columnToJoin} WHERE cpe.entity_id IN ($productIds) AND cpei.attribute_id = $visibilityAttr AND cpei.value IN (2,3,4) AND cpei.store_id IN ($tagalysStores);";
        $this->runSql($sql);
        // insert parent products of associated child products
        $sql = "REPLACE $tq (product_id, priority) SELECT DISTINCT cpr.parent_id, $priority FROM $cpr as cpr WHERE cpr.child_id IN ($productIds);";
        $this->runSql($sql);
    }

    public function _visibleInAnyStore($product) {
        $visible = false;
        $storesForTagalys = $this->tagalysConfiguration->getStoresForTagalys();
        foreach ($storesForTagalys as $storeId) {
            $this->storeManager->setCurrentStore($storeId);
            $productVisibility = $product->getVisibility();
            if ($productVisibility != 1) {
                $visible = true;
                break;
            }
        }
        return $visible;
    }

    public function getPrimaryProductId($productId) {
        $product = $this->productFactory->create()->load($productId);
        if ($product) {
            $productType = $product->getTypeId();
            $visibleInAnyStore = $this->_visibleInAnyStore($product);
            if (!$visibleInAnyStore) {
                // not visible individually
                if ($productType == 'simple' || $productType == 'virtual') {
                    // coulbe be attached to configurable product
                    $parentIds = $this->configurableProduct->getParentIdsByChild($productId);
                    if (count($parentIds) > 0) {
                        // check and return configurable product id
                        return $this->getPrimaryProductId($parentIds[0]);
                    } else {
                        // simple product which is not visible / an orphan simple product
                        return false;
                    }
                } else {
                    // configurable / grouped / bundled product that is not visible individually
                    return false;
                }
            } else {
                // any type of product that is visible individually. add to queue.
                return $productId;
            }
        } else {
            // product not found. might have to delete
            return $productId;
        }
        return false;
    }

    public function getProductsInQueueForAPI(){
        $queueTable = $this->resourceConnection->getTableName('tagalys_queue');
        $sql = "SELECT * FROM $queueTable";
        return $this->runSqlSelect($sql);
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

    public function getProductVisibilityAttrId(){
        if(is_null($this->visibilityAttrId)) {
            $ea = $this->resourceConnection->getTableName('eav_attribute');
            $eet = $this->resourceConnection->getTableName('eav_entity_type');
            $sql = "SELECT ea.attribute_id FROM $ea as ea INNER JOIN $eet as eet ON ea.entity_type_id = eet.entity_type_id WHERE eet.entity_table = 'catalog_product_entity' AND ea.attribute_code = 'visibility'";
            $rows = $this->runSqlSelect($sql);

            $this->visibilityAttrId = $rows[0]['attribute_id'];
        }
        return $this->visibilityAttrId;
    }

    public function paginateSqlInsert($productIds, $priority) {
        $rows = array_map(function($productId) use ($priority) {
            return "($productId, $priority)";
        }, $productIds);
        $this->paginateAndInsertRows($rows);
    }

    public function paginateSqlUpdate($productIds, $priority) {
        Utils::forEachChunk($productIds, $this->sqlBulkBatchSize, function($idsChunk) use ($priority){
            $values = implode(',', $idsChunk);
            $sql = "UPDATE {$this->tableName} SET priority=$priority WHERE product_id IN ($values);";
            $this->runSql($sql);
        });
    }

    public function paginateSqlDelete($productIds, $priority = null) {
        Utils::forEachChunk($productIds, $this->sqlBulkBatchSize, function($idsChunk) use($priority) {
            $values = implode(',', $idsChunk);
            $where = "product_id IN ($values)";
            if ($priority != null) {
                $where .= " AND priority=$priority";
            }
            $sql = "DELETE FROM {$this->tableName} WHERE $where;";
            $this->runSql($sql);
        });
        return true;
    }

    public function deleteByPriority($priority) {
        $sql = "DELETE FROM {$this->tableName} WHERE priority=$priority";
        return $this->runSql($sql);
    }

    public function removeDuplicatesFromQueue() {
        $sql = "SELECT * FROM {$this->tableName} ORDER BY priority DESC;";
        $rows = $this->runSqlSelect($sql);
        $productIdsHash = [];
        $validRows = [];
        foreach($rows as $row) {
            $productId = $row['product_id'];
            $priority = $row['priority'];
            if(!array_key_exists($productId, $productIdsHash)) {
                $validRows[] = "($productId, $priority)";
                $productIdsHash[$productId] = true;
            }
        }
        $this->paginateSqlDelete(array_keys($productIdsHash));
        $this->paginateAndInsertRows($validRows);
        return ['before' => count($rows), 'after' => count($validRows)];
    }
}
