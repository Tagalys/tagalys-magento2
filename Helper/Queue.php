<?php
namespace Tagalys\Sync\Helper;

class Queue extends \Magento\Framework\App\Helper\AbstractHelper
{
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
    }

    public function insertUnique($productIds) {
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
                    $this->insertPrimaryProducts($productsToInsert);
                } else {
                    $productsToInsert = implode('),(', $productsToInsert);
                    $sql = "REPLACE $queueTable (product_id) VALUES ($productsToInsert);";
                    $this->runSql($sql);
                }
                $offset += $perPage;
                $productsToInsert = array_slice($productIds, $offset, $perPage);
            }
        } catch (\Exception $e){
            $this->tagalysLogger->warn("insertUnique exception: " . json_encode(['message' => $e->getMessage(), 'product_ids' => $productIds, 'backtrace' => $e->getTrace()]));
        }
    }

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

    public function truncate() {
        $queue = $this->queueFactory->create();
        $connection = $queue->getResource()->getConnection();
        $tableName = $queue->getResource()->getMainTable();
        $connection->truncateTable($tableName);
    }

    // Not used anywhere.
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
    public function insertPrimaryProducts($productIds){
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
        $sql = "REPLACE $tq (product_id) SELECT DISTINCT cpe.entity_id FROM $cpe as cpe INNER JOIN $cpei as cpei ON cpe.{$columnToJoin} = cpei.{$columnToJoin} WHERE cpe.entity_id IN ($productIds) AND cpei.attribute_id = $visibilityAttr AND cpei.value IN (2,3,4) AND cpei.store_id IN ($tagalysStores);";
        $this->runSql($sql);
        // insert parent products of associated child products
        $sql = "REPLACE $tq (product_id) SELECT DISTINCT cpr.parent_id FROM $cpr as cpr WHERE cpr.child_id IN ($productIds);";
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

    public function getProductsInQueue(){
        $productIds = [];
        $queueTable = $this->resourceConnection->getTableName('tagalys_queue');
        $sql = "SELECT product_id FROM $queueTable";
        $rows = $this->runSqlSelect($sql);
        foreach ($rows as $row) {
            $productIds[] = $row['product_id'];
        }
        return $productIds;
    }

    public function removeProductIdsIn($productIds){
        if(!is_array($productIds)){
            $productIds = [$productIds];
        }
        if(count($productIds) > 0){
            $productIds = implode(',', $productIds);
            $queueTable = $this->resourceConnection->getTableName('tagalys_queue');
            $sql = "DELETE FROM $queueTable WHERE product_id IN ($productIds);";
            $this->runSql($sql);
            return true;
        }
        return false;
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
        $ea = $this->resourceConnection->getTableName('eav_attribute');
        $eet = $this->resourceConnection->getTableName('eav_entity_type');
        $sql = "SELECT ea.attribute_id FROM $ea as ea INNER JOIN $eet as eet ON ea.entity_type_id = eet.entity_type_id WHERE eet.entity_table = 'catalog_product_entity' AND ea.attribute_code = 'visibility'";
        $rows = $this->runSqlSelect($sql);
        return $rows[0]['attribute_id'];
    }
}
