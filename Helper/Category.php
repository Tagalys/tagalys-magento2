<?php
namespace Tagalys\Sync\Helper;

class Category extends \Magento\Framework\App\Helper\AbstractHelper
{
    private $updatedCategories = array();
    public function __construct(
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration,
        \Tagalys\Sync\Helper\Api $tagalysApi,
        \Tagalys\Sync\Helper\Queue $tagalysQueue,
        \Magento\Catalog\Model\ResourceModel\Category\Collection $categoryCollection,
        \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $categoryCollectionFactory,
        \Tagalys\Sync\Model\CategoryFactory $tagalysCategoryFactory,
        \Magento\Catalog\Model\CategoryFactory $categoryFactory,
        \Magento\Store\Model\StoreManagerInterface $storeManagerInterface,
        \Magento\Catalog\Model\ProductFactory $productFactory,
        \Magento\Framework\App\ResourceConnection $resourceConnection,
        \Magento\Framework\Model\ResourceModel\Iterator $resourceModelIterator,
        \Magento\Catalog\Api\Data\CategoryProductLinkInterfaceFactory $categoryProductLinkInterfaceFactory,
        \Magento\Catalog\Api\CategoryLinkRepositoryInterface $categoryLinkRepositoryInterface,
        \Magento\Framework\App\CacheInterface $cacheInterface,
        \Magento\Framework\Event\Manager $eventManager,
        \Magento\Framework\App\ProductMetadataInterface $productMetadataInterface,
        \Magento\Indexer\Model\IndexerFactory $indexerFactory,
        \Magento\Framework\Math\Random $random,
        \Magento\Framework\Registry $registry,
        \Magento\UrlRewrite\Model\UrlRewriteFactory $urlRewriteFactory,
        \Magento\UrlRewrite\Model\ResourceModel\UrlRewriteCollection $urlRewriteCollection
    ) {
        $this->tagalysConfiguration = $tagalysConfiguration;
        $this->random = $random;
        $this->_registry = $registry;
        $this->tagalysApi = $tagalysApi;
        $this->categoryCollection = $categoryCollection;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->tagalysCategoryFactory = $tagalysCategoryFactory;
        $this->productFactory = $productFactory;
        $this->categoryFactory = $categoryFactory;
        $this->storeManagerInterface = $storeManagerInterface;
        $this->resourceConnection = $resourceConnection;
        $this->resourceModelIterator = $resourceModelIterator;
        $this->categoryProductLinkInterfaceFactory = $categoryProductLinkInterfaceFactory;
        $this->categoryLinkRepositoryInterface = $categoryLinkRepositoryInterface;
        $this->cacheInterface = $cacheInterface;
        $this->eventManager = $eventManager;
        $this->productMetadataInterface = $productMetadataInterface;
        $this->indexerFactory = $indexerFactory;
        $this->urlRewriteFactory = $urlRewriteFactory;
        $this->urlRewriteCollection = $urlRewriteCollection;
        $this->tagalysQueue = $tagalysQueue;
        
        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/tagalys_categories.log');
        $this->logger = new \Zend\Log\Logger();
        $this->logger->addWriter($writer);
    }

    public function truncate() {
        $tagalysCategoryFactory = $this->tagalysCategoryFactory->create();
        $connection = $tagalysCategoryFactory->getResource()->getConnection();
        $tableName = $tagalysCategoryFactory->getResource()->getMainTable();
        $connection->truncateTable($tableName);
    }

    public function createOrUpdateWithData($storeId, $categoryId, $createData, $updateData)
    {
        $firstItem = $this->tagalysCategoryFactory->create()->getCollection()
            ->addFieldToFilter('category_id', $categoryId)
            ->addFieldToFilter('store_id', $storeId)
            ->getFirstItem();

        try {
            if ($id = $firstItem->getId()) {
                $updateData['category_id'] = $categoryId;
                $updateData['store_id'] = $storeId;
                $model = $this->tagalysCategoryFactory->create()->load($id)->addData($updateData);
                $model->setId($id)->save();
            } else {
                $createData['category_id'] = $categoryId;
                $createData['store_id'] = $storeId;
                $model = $this->tagalysCategoryFactory->create()->setData($createData);
                $insertId = $model->save()->getId();
            }
        } catch (Exception $e) {
        
        }
    }
    public function updateWithData($storeId, $categoryId, $updateData)
    {
        $firstItem = $this->tagalysCategoryFactory->create()->getCollection()
            ->addFieldToFilter('category_id', $categoryId)
            ->addFieldToFilter('store_id', $storeId)
            ->getFirstItem();

        try {
            if ($id = $firstItem->getId()) {
                $updateData['category_id'] = $categoryId;
                $updateData['store_id'] = $storeId;
                $model = $this->tagalysCategoryFactory->create()->load($id)->addData($updateData);
                $model->setId($id)->save();
                return true;
            }
            return false;
        } catch (Exception $e) {
            return false;
        }
    }
    public function markStoreCategoryIdsForDeletionExcept($storeId, $categoryIds) {
        $collection = $this->tagalysCategoryFactory->create()->getCollection()->addFieldToFilter('store_id', $storeId);
        foreach ($collection as $collectionItem) {
        if (!in_array((int)$collectionItem->getCategoryId(), $categoryIds)) {
            $collectionItem->addData(array('marked_for_deletion' => 1))->save();
        }
        }
    }

    public function isMultiStoreWarningRequired()
    {
        $allStores = $this->tagalysConfiguration->getAllWebsiteStores();
        $showMultiStoreConfig = false;
        $checkAllCategories = false;
        if (count($allStores) > 1) {
        $rootCategories = array();
        foreach ($allStores as $store) {
            $rootCategoryId = $this->storeManagerInterface->getStore($store['value'])->getRootCategoryId();
            if (in_array($rootCategoryId, $rootCategories)) {
            $checkAllCategories = true;
            break;
            } else {
            array_push($rootCategories, $rootCategoryId);
            }
        }
        }

        if ($checkAllCategories) {
        $allCategories = array();
        foreach ($allStores as $store) {
            $rootCategoryId = $this->storeManagerInterface->getStore($store['value'])->getRootCategoryId();
            $categories = $this->categoryCollection
            ->setStoreId($store['value'])
            ->addFieldToFilter('is_active', 1)
            ->addAttributeToFilter('path', array('like' => "1/{$rootCategoryId}/%"))
            ->addAttributeToSelect('id');
            foreach ($categories as $cat) {
            if (in_array($cat->getId(), $allCategories)) {
                $showMultiStoreConfig = true;
                break;
            } else {
                array_push($allCategories, $cat->getId());
            }
            }
        }
        }
        return $showMultiStoreConfig;
    }

    public function transitionFromCategoriesConfig()
    {
        $categoryIds = $this->tagalysConfiguration->getConfig("category_ids", true);
        $storesForTagalys = $this->tagalysConfiguration->getStoresForTagalys();
        foreach ($storesForTagalys as $storeId) {
        $originalStoreId = $this->storeManagerInterface->getStore();
        $this->storeManagerInterface->setCurrentStore($storeId);
        foreach ($categoryIds as $i => $categoryId) {
            $category = $this->categoryFactory->create()->load($categoryId);
            $categoryActive = $category->getIsActive();
            if ($categoryActive && ($category->getDisplayMode() != 'PAGE')) {
            // TODO: createOrUpdateWithData() & Mage_Catalog_Model_Category::DM_PAGE -> PAGE
            $this->createOrUpdateWithData($storeId, $categoryId, array('positions_sync_required' => 0, 'marked_for_deletion' => 0, 'status' => 'pending_sync'), array('marked_for_deletion' => 0));
            }
        }
        $this->storeManagerInterface->setCurrentStore($originalStoreId);
        }
    }

    public function isProductPushDownAllowed($categoryId)
    {
        $allStores = $this->storeManagerInterface->getStores();
        $activeInStores = 0;
        if (count($allStores) == 1) {
        // Single Store
        return true;
        }
        // Multiple Stores
        foreach ($allStores as $store) {
        $categories = $this->categoryCollection
            ->setStoreId($store['value'])
            ->addFieldToFilter('is_active', 1)
            ->addFieldToFilter('entity_id', $categoryId)
            ->addAttributeToSelect('id');
        if ($categories->count() > 0) {
            $activeInStores++;
            if ($activeInStores > 1) {
            return ($this->tagalysConfiguration->getConfig("listing_pages:same_or_similar_products_across_all_stores") == '1');
            }
        }
        }
        return true;
    }

    public function maintenanceSync()
    {
        // once a day
        $listingPagesEnabled = ($this->tagalysConfiguration->getConfig("module:listingpages:enabled") == '1');
        if ($listingPagesEnabled) {
        // 1. try and sync all failed categories - mark positions_sync_required as 1 for all failed categories - this will then try and sync the categories again
        $failedCategories = $this->tagalysCategoryFactory->create()->getCollection()
            ->addFieldToFilter('status', 'failed')
            ->addFieldToFilter('marked_for_deletion', 0);
        foreach ($failedCategories as $i => $failedCategory) {
            $failedCategory->addData(array('status' => 'pending_sync'))->save();
        }

        // 2. if preference is to power all categories, loop through all categories and add missing items to the tagalys_core_categories table
        // TODO
        // 3. send all category ids to be powered by tagalys - tagalys will delete other ids
        $storesForTagalys = $this->tagalysConfiguration->getStoresForTagalys();
        $categoriesForTagalys = array();
        foreach ($storesForTagalys as $key => $storeId) {
            $categoriesForTagalys[$storeId] = array();
            $storeCategories = $this->tagalysCategoryFactory->create()->getCollection()
            ->addFieldToFilter('store_id', $storeId);
            foreach ($storeCategories as $i => $storeCategory) {
            array_push($categoriesForTagalys[$storeId], '__categories--' . $storeCategory->getCategoryId());
            }
        }
        $this->tagalysApi->clientApiCall('/v1/mpages/_platform/verify_enabled_pages', array('enabled_pages' => $categoriesForTagalys));
        }
        return true;
    }

    public function _checkSyncLock($syncStatus)
    {
        if ($syncStatus['locked_by'] == null) {
            return true;
        } else {
            // some other process has claimed the thread. if a crash occours, check last updated at < 15 minutes ago and try again.
            $lockedAt = new \DateTime($syncStatus['updated_at']);
            $now = new \DateTime();
            $intervalSeconds = $now->getTimestamp() - $lockedAt->getTimestamp();
            $minSecondsForOverride = 5 * 60;
            if ($intervalSeconds > $minSecondsForOverride) {
                $this->tagalysApi->log('warn', 'Overriding stale locked process for categories sync', array('pid' => $syncStatus['locked_by'], 'locked_seconds_ago' => $intervalSeconds));
                return true;
            } else {
                $this->tagalysApi->log('warn', 'Categories sync locked by another process', array('pid' => $syncStatus['locked_by'], 'locked_seconds_ago' => $intervalSeconds));
                return false;
            }
        }
    }

    public function markPositionsSyncRequired($storeId, $categoryId)
    {
        $firstItem = $this->tagalysCategoryFactory->create()->getCollection()
            ->addFieldToFilter('store_id', $storeId)
            ->addFieldToFilter('category_id', $categoryId)
            ->getFirstItem();
        if ($id = $firstItem->getId()) {
        $firstItem->addData(array('positions_sync_required' => 1))->save();
        }
        return true;
    }
    public function markPositionsSyncRequiredForCategories($storeId, $categoryIds) {
        $conn = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('tagalys_category');
        $whereData = array(
            'status = ?' => 'powered_by_tagalys'
        );
        if ($storeId !== 'all') {
            $whereData['store_id = ?'] = $storeId;
        }
        if ($categoryIds !== 'all') {
            $whereData['category_id IN (?)'] = $categoryIds;
        }
        $updateData = array(
            'positions_sync_required' => 1
        );
        $conn->update($tableName, $updateData, $whereData);
        return true;
    }
    public function markFailedCategoriesForRetrying() {
        $conn = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('tagalys_category');
        $whereData = array(
            'status = ?' => 'failed'
        );
        $updateData = array(
            'status' => 'pending_sync'
        );
        $conn->update($tableName, $updateData, $whereData);
        return true;
    }
    public function getEnabledCount($storeId)
    {
        return $this->tagalysCategoryFactory->create()->getCollection()
            ->addFieldToFilter('store_id', $storeId)
            ->addFieldToFilter('marked_for_deletion', 0)
            ->count();
    }
    public function getPendingSyncCount($storeId)
    {
        return $this->tagalysCategoryFactory->create()->getCollection()
            ->addFieldToFilter('store_id', $storeId)
            ->addFieldToFilter('status', 'pending_sync')
            ->addFieldToFilter('marked_for_deletion', 0)
            ->count();
    }
    public function getRequiringPositionsSyncCount($storeId)
    {
        return $this->tagalysCategoryFactory->create()->getCollection()
            ->addFieldToFilter('store_id', $storeId)
            ->addFieldToFilter('status', 'powered_by_tagalys')
            ->addFieldToFilter('positions_sync_required', 1)
            ->addFieldToFilter('marked_for_deletion', 0)
            ->count();
    }
    public function getRequiresPositionsSyncCollection()
    {
        $categoriesToSync = $this->tagalysCategoryFactory->create()->getCollection()
            ->addFieldToFilter('status', 'powered_by_tagalys')
            ->addFieldToFilter('positions_sync_required', 1)
            ->addFieldToFilter('marked_for_deletion', 0);
        return $categoriesToSync;
    }

    public function updatePositionsIfRequired($maxProductsPerCronRun = 50, $perPage = 5, $force = false)
    {
        $listingPagesEnabled = ($this->tagalysConfiguration->getConfig("module:listingpages:enabled") == '1');
        if ($listingPagesEnabled || $force) {
            $pid = $this->random->getRandomString(24);
            $this->tagalysApi->log('local', '1. Started updatePositionsIfRequired', array('pid' => $pid));
            $categoriesSyncStatus = $this->tagalysConfiguration->getConfig("categories_sync_status", true);
            if ($this->_checkSyncLock($categoriesSyncStatus)) {
                $utcNow = new \DateTime("now", new \DateTimeZone('UTC'));
                $timeNow = $utcNow->format(\DateTime::ATOM);
                $syncStatus = array(
                'updated_at' => $timeNow,
                'locked_by' => $pid
                );
                $this->tagalysConfiguration->setConfig('categories_sync_status', $syncStatus, true);
                $collection = $this->getRequiresPositionsSyncCollection();
                $remainingCount = $collection->count();
                // $this->logger("updatePositionsIfRequired: remainingCount: {$remainingCount}", null, 'tagalys_processes.log', true);
                $countToSyncInCronRun = min($remainingCount, $maxProductsPerCronRun);
                $numberCompleted = 0;
                $circuitBreaker = 0;
                while ($numberCompleted < $countToSyncInCronRun && $circuitBreaker < 26) {
                    $circuitBreaker += 1;
                    $categoriesToSync = $this->getRequiresPositionsSyncCollection()->setPageSize($perPage);
                    $utcNow = new \DateTime("now", new \DateTimeZone('UTC'));
                    $timeNow = $utcNow->format(\DateTime::ATOM);
                    $syncStatus['updated_at'] = $timeNow;
                    $this->tagalysConfiguration->setConfig('categories_sync_status', $syncStatus, true);
                    foreach ($categoriesToSync as $categoryToSync) {
                        $storeId = $categoryToSync->getStoreId();
                        $categoryId = $categoryToSync->getCategoryId();
                        $newPositions = $this->tagalysApi->storeApiCall($storeId . '', '/v1/mpages/_platform/__categories-' . $categoryId . '/positions', array());
                        if ($newPositions != false) {
                            $this->performCategoryPositionUpdate($storeId, $categoryId, $newPositions['positions']);
                            $categoryToSync->addData(array('positions_sync_required' => 0, 'positions_synced_at' => date("Y-m-d H:i:s")))->save();
                        } else {
                            // api call failed
                        }
                    }
                    $numberCompleted += $categoriesToSync->count();
                    // $this->logger("updatePositionsIfRequired: completed {$numberCompleted}", null, 'tagalys_processes.log', true);
                }
                $syncStatus['locked_by'] = null;
                $this->tagalysConfiguration->setConfig('categories_sync_status', $syncStatus, true);
            }
        }
    }

    public function _setPositionSortOrder($storeId, $categoryId) {
        $mappedStoreIds = $this->tagalysConfiguration->getMappedStores($storeId, true);
        foreach ($mappedStoreIds as $mappedStoreId) {
            $category = $this->categoryFactory->create()->setStoreId($mappedStoreId)->load($categoryId);
            $category->setDefaultSortBy('position')->save();
        }
    }

    public function _updatePositions($storeId, $categoryId, $positions) {
        $indexTable = $this->getIndexTableName($storeId);
        $pushDown = false;
        $positionOffset = count($positions) + 1;
        $whereData = array(
            'category_id = ?' => (int)$categoryId,
            'position <= ?' => $positionOffset
        );
        $updateData = array(
            'position' => (count($positions) + 2)
        );
        if($this->isProductPushDownAllowed($categoryId)){
            $pushDown = true;
        } else {
            $sql = "SELECT product_id FROM $indexTable WHERE category_id = $categoryId AND position <= $positionOffset AND store_id = $storeId AND visibility IN (2, 4); ";
            $result = $this->runSqlSelect($sql);
            $productsInStore = array();
            foreach ($result as $row) {
                $productsInStore[] = (int) $row['product_id'];
            }
            if(count($productsInStore) > 0){
                $pushDown = true;
                $productsInStore = implode(', ', $productsInStore);
                $whereData["product_id IN ($productsInStore)"] = "";
            }
        }
        if ($pushDown) {
            $this->runSqlForCategoryPositions($updateData, $whereData);
        }

        foreach ($positions as $productId => $productPosition) {
            $whereData = array(
                'category_id = ?' => (int)$categoryId,
                'product_id = ?' => (int)$productId
            );
            $updateData = array(
                'position' => (int)$productPosition
            );
            $this->runSqlForCategoryPositions($updateData, $whereData);
        }
        return true;
    }

    public function _updatePositionsReverse($storeId, $categoryId, $positions) {
        $indexTable = $this->getIndexTable($storeId);
        // check magento version before getting table name
        $pushDown = false;
        $positionOffset = 100;
        $whereData = array(
            'category_id = ?' => (int) $categoryId,
            'position <= ?' => $positionOffset
        );
        $updateData = array(
            'position' => (count($positions) + 2)
        );
        if ($this->isProductPushDownAllowed($categoryId)) {
            $pushDown = true;
        } else {
            $sql = "SELECT product_id FROM $indexTable WHERE category_id = $categoryId AND position <= $positionOffset AND store_id = $storeId AND visibility IN (2, 4); ";
            $result = $this->runSqlSelect($sql);
            $productsInStore = array();
            foreach ($result as $row) {
                $productsInStore[] = (int) $row['product_id'];
            }
            if (count($productsInStore) > 0) {
                $pushDown = true;
                $productsInStore = implode(', ', $productsInStore);
                $whereData["product_id IN ($productsInStore)"] = "";
            }
        }
        if ($pushDown) {
            $this->runSqlForCategoryPositions($updateData, $whereData);
        }

        if ($this->isProductPushDownAllowed($categoryId)) {
            $whereData = array(
                'category_id = ?' => (int)$categoryId,
                'position >= ?' => 100
            );
            $updateData = array(
                'position' => 99
            );
            $this->runSqlForCategoryPositions($updateData, $whereData);
        }

        $totalPositions = count($positions);
        foreach ($positions as $productId => $productPosition) {
            $whereData = array(
                'category_id = ?' => (int)$categoryId,
                'product_id = ?' => (int)$productId
            );
            $updateData = array(
                'position' => 101 + $totalPositions - (int)$productPosition
            );
            $this->runSqlForCategoryPositions($updateData, $whereData);
        }
        return true;
    }

    public function getRemainingForSync()
    {
        return $this->tagalysCategoryFactory->create()->getCollection()
        ->addFieldToFilter('status', 'pending_sync')
        ->addFieldToFilter('marked_for_deletion', 0)->count();
    }
    public function getRemainingForDelete()
    {
        return $this->tagalysCategoryFactory->create()->getCollection()
        ->addFieldToFilter('marked_for_deletion', 1)->count();
    }
    public function syncAll($force = false)
    {
        $remainingForSync = $this->getRemainingForSync();
        $remainingForDelete = $this->getRemainingForDelete();
        // echo('syncAll: ' . json_encode(compact('remainingForSync', 'remainingForDelete')));
        while ($remainingForSync > 0 || $remainingForDelete > 0) {
        $this->sync(50, $force);
        $remainingForSync = $this->getRemainingForSync();
        $remainingForDelete = $this->getRemainingForDelete();
        // echo('syncAll: ' . json_encode(compact('remainingForSync', 'remainingForDelete')));
        }
    }
    public function getStoreCategoryDetails($storeId, $categoryId) {
        $originalStoreId = $this->storeManagerInterface->getStore()->getId();
        $this->storeManagerInterface->setCurrentStore($storeId);
        $category = null;
        $category = $this->categoryFactory->create()->load($categoryId);
        $categoryActive = $category->getIsActive();
        if ($categoryActive) {
            return array(
                "id" => "__categories-$categoryId",
                "slug" => $category->getUrl(),
                "path" => $category->getUrlPath(),
                "enabled" => true,
                "name" => implode(' / ', array_slice(explode(' |>| ', $this->tagalysConfiguration->getCategoryName($category)), 1)),
                "filters" => array(
                array(
                    "field" => "__categories",
                    "value" => $categoryId
                ),
                array(
                    "field" => "visibility",
                    "tag_jsons" => array("{\"id\":\"2\",\"name\":\"Catalog\"}", "{\"id\":\"4\",\"name\":\"Catalog, Search\"}")
                )
            ));
        }
        $this->storeManagerInterface->setCurrentStore($originalStoreId);
    }
    public function sync($max, $force = false)
    {
        $listingPagesEnabled = ($this->tagalysConfiguration->getConfig("module:listingpages:enabled") == '1');
        if ($listingPagesEnabled || $force) {
        $detailsToSync = array();

        // save
        $categoriesToSync = $this->tagalysCategoryFactory->create()->getCollection()
            ->addFieldToFilter('status', 'pending_sync')
            ->addFieldToFilter('marked_for_deletion', 0)
            ->setPageSize($max);
        foreach ($categoriesToSync as $i => $categoryToSync) {
            $storeId = $categoryToSync->getStoreId();
            array_push($detailsToSync, array('perform' => 'save', 'store_id' => $storeId, 'payload' => $this->getStoreCategoryDetails($storeId, $categoryToSync->getCategoryId())));
        }
        // delete
        $categoriesToDelete = $this->tagalysCategoryFactory->create()->getCollection()
            ->addFieldToFilter('marked_for_deletion', 1)
            ->setPageSize($max);
        foreach ($categoriesToDelete as $i => $categoryToDelete) {
            $storeId = $categoryToDelete->getStoreId();
            $categoryId = $categoryToDelete->getCategoryId();
            array_push($detailsToSync, array('perform' => 'delete', 'store_id' => $storeId, 'payload' => array('id' => "__categories-{$categoryId}")));
        }

        if (count($detailsToSync) > 0) {
            // sync
            $tagalysResponse = $this->tagalysApi->clientApiCall('/v1/mpages/_sync_platform_pages', array('actions' => $detailsToSync));

            if ($tagalysResponse != false) {
            foreach ($tagalysResponse['save_actions'] as $i => $saveActionResponse) {
                $firstItem = $this->tagalysCategoryFactory->create()->getCollection()
                ->addFieldToFilter('store_id', $saveActionResponse['store_id'])
                ->addFieldToFilter('category_id', explode('-', $saveActionResponse['id'])[1])
                ->getFirstItem();
                if ($id = $firstItem->getId()) {
                    if ($saveActionResponse['saved']) {
                        $firstItem->addData(array('status' => 'powered_by_tagalys', 'positions_sync_required' => 1))->save();
                    } else {
                        $firstItem->addData(array('status' => 'failed'))->save();
                    }
                }
            }
            foreach ($categoriesToDelete as $i => $categoryToDelete) {
                $categoryToDelete->delete();
            }
            }
        }
        }
    }

    public function assignParentCategoriesToAllProducts($viaDb = false){
        $productCollection = $this->productFactory->create()->getCollection()
            ->addAttributeToFilter('status', 1)
            ->addAttributeToFilter('visibility', array("neq" => 1))
            ->addAttributeToSelect('entity_id, product_id')
            ->load();
        $this->resourceModelIterator->walk($productCollection->getSelect(), array(array($this, 'assignParentCategoriesToProductHandler')), array('viaDb' => $viaDb));
    }

    public function assignParentCategoriesToProductHandler($args){
        $this->assignParentCategoriesToProductId($args['row']['entity_id'], $args['viaDb']);
    }

    public function assignParentCategoriesToProductId($productId, $viaDb = false) {
        $this->logger->info( "assignParentCategoriesToProductId: $productId");
        $product = $this->productFactory->create()->load($productId);
        $categoryIds = $product->getCategoryIds();
        $assignedParents = array();
        foreach($categoryIds as $categoryId){
            $category = $this->categoryFactory->create()->load($categoryId);
            foreach($category->getParentCategories() as $parent){
                if ((int)$parent->getLevel() > 1) {
                    if(!in_array($parent->getId(), $categoryIds) and !in_array($parent->getId(), $assignedParents)){
                        array_push($assignedParents, $parent->getId());
                        if ($viaDb) {
                            $this->assignProductToCategoryViaDb($parent->getId(), $product);
                        } else {
                            $this->assignProductToCategory($parent->getId(), $product);
                        }
                    }
                }
            }
        }
    }

    public function assignProductToCategory($categoryId, $product, $viaDb = false){
        $this->logger->info("assignProductToCategory: {$categoryId}");
        $productSku = $product->getSku();
        $categoryProductLink = $this->categoryProductLinkInterfaceFactory->create();
        $categoryProductLink->setSku($productSku);
        $categoryProductLink->setCategoryId($categoryId);
        $categoryProductLink->setPosition(999);
        $this->categoryLinkRepositoryInterface->save($categoryProductLink);
    }
    public function assignProductToCategoryViaDb($categoryId, $product){
        $this->logger->info("assignProductToCategoryViaDb: {$categoryId}");
        $conn = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('catalog_category_product');
        
        $sortDirection = $this->tagalysConfiguration->getConfig('listing_pages:position_sort_direction');
        $positionToAssign = ($sortDirection == 'desc' ? 1 : 9999);
        $assignData = array('category_id'=>(int)$categoryId, 'product_id'=>(int)($product->getId()), 'position' => $positionToAssign);
        $conn->insert($table, $assignData);
    }

    public function uiPoweredByTagalys($storeId, $categoryId) {
        try {
            $firstItem = $this->tagalysCategoryFactory->create()->getCollection()
                ->addFieldToFilter('store_id', $storeId)
                ->addFieldToFilter('category_id', $categoryId)
                ->addFieldToFilter('status', 'powered_by_tagalys')
                ->addFieldToFilter('marked_for_deletion', 0)
                ->getFirstItem();
            if ($id = $firstItem->getId()) {
                return true;
            } else {
                return false;
            }
        } catch (Exception $e) {
            return false;
        }
    }
    public function pushDownProductsIfRequired($productIds, $productCategories = null, $indexerToRun = 'product') {
        // called from observers when new products are added to categories - in position ascending order, they should be positioned at the bottom of the page.
        $listingpagesEnabled = $this->tagalysConfiguration->getConfig('module:listingpages:enabled');
        if($listingpagesEnabled == '1') {
            $sortDirection = $this->tagalysConfiguration->getConfig('listing_pages:position_sort_direction');
            if($sortDirection == 'asc'){
                $tagalysCategories = $this->getTagalysCategories($productCategories);
                if (count($tagalysCategories) > 0) {
                    $whereData = array(
                        'category_id IN (?)' => $tagalysCategories,
                        'product_id IN (?)' => $productIds,
                        'position = ?' => 0
                    );
                    $updateData = array(
                        'position' => 9999
                    );
                    $this->runSqlForCategoryPositions($updateData, $whereData);
                    $categoryProductIndexer = $this->indexerFactory->create()->load('catalog_category_product');
                    if ($categoryProductIndexer->isScheduled() === false) {
                        // reindex if index mode is update on save. not required for schedule because we don't want these showing up so it has the same effect. when tagalys syncs and updates positions, reindex will be triggered.
                        switch($indexerToRun) {
                            case 'product':
                                $reindexAndClearCacheImmediately = $this->tagalysConfiguration->getConfig('listing_pages:reindex_and_clear_cache_immediately');
                                if ($reindexAndClearCacheImmediately == '1') {
                                    $indexer = $this->indexerFactory->create()->load('catalog_product_category');
                                    $indexer->reindexList($productIds);
                                }
                                // cache may already be getting updated. even otherwise, we don't need to update here as we don't want products to show up anyway. caches are cleared when positions are updated via Tagalys.
                                break;
                            case 'category':
                                array_push($this->updatedCategories, $tagalysCategories);
                                $this->reindexUpdatedCategories();
                                break;
                        }
                    }
                }
            }
        }
    }

    public function runSqlForCategoryPositions($updateData, $whereData) {
        $conn = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('catalog_category_product');
        $conn->update($tableName, $updateData, $whereData);
    }

    public function getTagalysCategories($categoryIds = null) {
        $conn = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('tagalys_category');
        $select = $conn->select()->from($tableName)->where('marked_for_deletion = ? and status != "failed"', 0);
        if($categoryIds!=null && is_array($categoryIds) && count($categoryIds) > 0 ){
        $select->where('category_id IN (?)', $categoryIds);
        }
        $result = $conn->fetchAll($select);
        $tagalysCategories = array();
        foreach($result as $row){
            $tagalysCategories[] = $row['category_id'];
        }
        $tagalysCategories = array_unique($tagalysCategories);
        return $tagalysCategories;
    }

    public function performCategoryPositionUpdate($storeId, $categoryId, $positions) {
        $sortOrder = $this->tagalysConfiguration->getConfig('listing_pages:position_sort_direction');
        if($sortOrder == 'asc') {
            $this->_updatePositions($storeId, $categoryId, $positions);
        } else {
            $this->_updatePositionsReverse($storeId, $categoryId, $positions);
        }
        $this->_setPositionSortOrder($storeId, $categoryId);
        $this->reindexUpdatedCategories($categoryId);
        return true;
    }
    
    public function createTagalysParentCategory($storeId, $categoryDetails) {
        $rootCategoryId = $this->storeManagerInterface->getStore($storeId)->getRootCategoryId();
        $categoryDetails['is_active'] = false;
        $categoryId = $this->_createCategory($rootCategoryId, $categoryDetails);
        if($categoryId){
            $this->setTagalysParentCategory($storeId, $categoryId);
            return $categoryId;
        }
        return false;
    }

    public function createCategory($categoryDetails, $forStores) {
        $parentCategoryId = $this->getTagalysParentCategory($forStores[0]);
        if ($parentCategoryId == null) {
            throw new \Exception("Tagalys parent category not created. Please enable Smart Categories in Tagalys Configuration.");
        }
        $categoryDetails['is_active'] = false;
        $categoryId = $this->_createCategory($parentCategoryId, $categoryDetails);
        foreach ($forStores as $sid) {
            $this->updateCategoryDetails($categoryId, ['is_active'=> true], $sid);
        }
        return $categoryId;
    }

    public function updateCategoryDetails($categoryId, $categoryDetails, $forStores = []) {
        $this->logger->info("updateCategoryDetails: category_id: $categoryId, categoryDetails: ".json_encode($categoryDetails));
        if(!is_array($forStores)){
            $forStores = [$forStores];
        }
        $category = $this->categoryFactory->create()->setStoreId(0)->load($categoryId);
        if($category->getId() == null){
            throw new \Exception("Platform category not found");
        }
        $categoryDetails['default_sort_by'] = 'position';
        if (count($forStores) > 0){
            foreach ($forStores as $storeId) {
                $category = $this->categoryFactory->create()->setStoreId($storeId)->load($categoryId);
                $category->addData($categoryDetails)->save();
            }
        } else {
            $category->addData($categoryDetails)->save();
        }
        $this->categoryUpdateAfter($category);
        return $categoryId;
    }

    public function categoryUpdateAfter($category){
        // TODO: Remove in future releases
        if($this->isLegacyMpageCategory($category)){
            $this->logger->info("categoryUpdateAfter: Updating url_rewrite for legacy mpage category, category_id: ".$category->getId());
            $urlRewrite = $this->urlRewriteCollection->addFieldToFilter('target_path', "catalog/category/view/id/{$category->getId()}")->getFirstItem();
            $urlRewrite->setRequestPath("m/{$category->getUrlKey()}")->save();
        }
    }

    public function deleteTagalysCategory($categoryId) {
        $category = $this->categoryFactory->create()->load($categoryId);
        if($category->getId() === null){
            return true;
        }
        $allowDelete = $this->isTagalysCreated($categoryId);
        if($allowDelete){
            $this->_registry->register("isSecureArea", true);
            $category->delete();
            return true;
        }
        throw new \Exception("This category cannot be deleted because it wasn't created by Tagalys");
    }

    public function _createCategory($parentId, $categoryDetails){
        $this->logger->info("_createCategory: parent_id: $parentId, categoryDetails: ".json_encode($categoryDetails));
        $category = $this->categoryFactory->create();
        $parent = $this->categoryFactory->create()->load($parentId);
        $category->setData($categoryDetails);
        $category->addData([
            'parent_id' => $parentId,
            'path' => $parent->getPath(),
            'default_sort_by' => 'position',
            'display_mode' => \Magento\Catalog\Model\Category::DM_PRODUCT,
            'include_in_menu' => 0,
            'is_anchor' => 1
        ]);
        $category->setStoreId(0);
        $category->save();
        return $category->getId();
    }

    public function bulkAssignProductsToCategoryAndRemove($storeId, $categoryId, $productPositions) {
        if($this->isTagalysCreated($categoryId)){
          $productsToRemove = $this->getProductsToRemove($storeId, $categoryId, $productPositions);
          $this->_paginateSqlRemove($categoryId, $productsToRemove);
          $this->bulkAssignProductsToCategoryViaDb($categoryId, $productPositions);
          $this->reindexUpdatedCategories();
          return true;
        }
        throw new \Exception("Error: this category wasn't created by Tagalys");
    }

    private function getProductsToRemove($storeId, $categoryId, $newProducts){
        $productsToRemove = [];
        $indexTable = $this->getIndexTableName($storeId);
        // $sql = "SELECT DISTINCT cpe.entity_id as product_id FROM $cpe as cpe INNER JOIN $cpei as cpei ON cpe.entity_id = cpei.entity_id WHERE cpe.updated_at > '$lastUpdateAt' AND cpei.attribute_id = $attrId AND cpei.value IN (2,3,4) AND cpei.store_id IN ($stores)";
        // use this type of query here. dont rely on index table
        $sql = "SELECT product_id FROM $indexTable WHERE category_id = $categoryId AND store_id = $storeId AND visibility IN (2, 4); ";
        $result = $this->runSqlSelect($sql);
        foreach ($result as $row) {
            if(!in_array($row['product_id'], $newProducts)){
                $productsToRemove[] = $row['product_id'];
            }
        }
        return $productsToRemove;
    }

    private function getProductsNotIn($categoryId, $productPositions){
        $tableName = $this->resourceConnection->getTableName('catalog_category_product');
        $sql = "SELECT product_id FROM $tableName WHERE category_id=$categoryId";
        $select = $this->runSqlSelect($sql);
        $productsInTable = [];
        foreach($select as $row){
            $productsInTable[] = $row['product_id'];
        }
        return array_diff($productsInTable, $productPositions);
    }

    private function bulkAssignProductsToCategoryViaDb($categoryId, $productPositions) {
        if(count($productPositions)>0){
            if($this->tagalysConfiguration->isSortedReverse()){
                array_reverse($productPositions);
            }
            $this->paginateSqlInsert($categoryId, $productPositions);
            $this->updatedCategories[] = $categoryId;
        }
    }

    private function paginateSqlInsert($categoryId, $productPositions) {
        $existingProducts = [];
        $rowsToInsert = [];
        $deletedProducts = [];
        $productCount = count($productPositions);
        $productsInSystem = $this->filterDeletedProducts($productPositions);
        $tableName = $this->resourceConnection->getTableName('catalog_category_product');
        $sql = "SELECT product_id FROM $tableName WHERE category_id=$categoryId;";
        $rows = $this->runSqlSelect($sql);
        foreach ($rows as $row) {
            $existingProducts[] = $row['product_id'];
        }
        for ($index=1; $index <= $productCount; $index++) {
            $productId = $productPositions[$index-1];
            if(in_array($productId, $existingProducts)){
                $updateSql = "UPDATE $tableName SET position=$index WHERE category_id=$categoryId AND product_id=$productId;";
                $this->runSql($updateSql);
            } else {
                if (in_array($productId, $productsInSystem)){
                    $rowsToInsert[] = "($categoryId, $productId, $index)";
                } else {
                    $deletedProducts[] = $productId;
                }
            }
            if( ($index % 500 == 0 || $index == $productCount) && count($rowsToInsert) > 0 ){
                $insertSql = "INSERT INTO $tableName (category_id, product_id, position) VALUES ";
                $values = implode(', ', $rowsToInsert);
                $query = $insertSql . $values . ';';
                $rowsToInsert = [];
                $this->runSql($query);
            }
        }
        // add deleted product ids to sync queue
        $this->tagalysQueue->insertUnique($deletedProducts);
    }

    private function filterDeletedProducts($productIds){
        $cpe = $this->resourceConnection->getTableName('catalog_product_entity');
        $productInSystem = [];
        $count = count($productIds);
        $thisBatch = [];
        for ($index = 0; $index < $count; $index++) {
            $thisBatch[] = $productIds[$index];
            if (($index % 500 == 0 || $index == $count-1)) {
                $thisBatch = implode(',', $thisBatch);
                $sql = "SELECT entity_id FROM $cpe WHERE entity_id IN ($thisBatch)";
                $rows = $this->runSqlSelect($sql);
                foreach ($rows as $row) {
                    $productInSystem[] = $row['entity_id'];
                }
                $thisBatch = [];
            }
        }
        return $productInSystem;
    }

    private function _paginateSqlRemove($categoryId, $products){
        $perPage = 100;
        $offset = 0;
        $tableName = $this->resourceConnection->getTableName('catalog_category_product');
        $productsToDelete = array_slice($products, $offset, $perPage);
        while(count($productsToDelete)>0){
            $productsToDelete = implode(', ', $productsToDelete);
            $sql = "DELETE FROM $tableName WHERE category_id=$categoryId AND product_id IN ($productsToDelete);";
            $this->runSql($sql);
            $offset += $perPage;
            $productsToDelete = array_slice($products, $offset, $perPage);
        }
        $this->updatedCategories[] = $categoryId;
    }

    private function runSql($sql) {
        // Not for SELECT
        $conn = $this->resourceConnection->getConnection();
        $conn->query($sql);
    }
    private function runSqlSelect($sql) {
        $conn = $this->resourceConnection->getConnection();
        return $conn->fetchAll($sql);
    }
    public function reindexUpdatedCategories($categoryId=null){
        if (isset($categoryId)){
            array_push($this->updatedCategories, $categoryId);
        }
        $this->updatedCategories = array_unique($this->updatedCategories);
        $this->logger->info("reindexUpdatedCategories: categoryIds: ".json_encode($this->updatedCategories));
        $indexer = $this->indexerFactory->create()->load('catalog_category_product');
        $indexer->reindexList($this->updatedCategories);
        $clearCache = $this->tagalysConfiguration->getConfig('listing_pages:clear_cache_automatically', true);
        if ($clearCache) {
            $this->clearCacheForCategories($this->updatedCategories);
        }
        $this->updatedCategories = [];
    }

    public function clearCacheForCategories($categoryIds) {
        foreach($categoryIds as $categoryId) {
            // clear magento cache
            $category = $this->categoryFactory->create()->load($categoryId);
            $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $category]);
        }
        // Tagalys custom cache clear event
        $categoryIdsObj = new \Magento\Framework\DataObject(array('category_ids' => $categoryIds));
        $this->eventManager->dispatch('tagalys_category_positions_updated', ['tgls_data' => $categoryIdsObj]);
    }

    public function isTagalysCreated($category) {
        if(!is_object($category)){
            $category = $this->categoryCollection->addAttributeToSelect('parent_id')->addAttributeToFilter('entity_id', $category)->setPage(1,1)->getFirstItem();
            if(!$category->getId()){
                return false;
            }
        }
        $parentCategories = $this->getAllTagalysParentCategories();
        if(in_array($category->getId(), $parentCategories)){
            return true;
        }
        if($category->getId()){
            $parentId = $category->getParentId();
            if(in_array($parentId, $parentCategories)){
                return true;
            }
        }
    }
    
    public function getTagalysCreatedCategories() {
        $tagalysCreated = $this->getAllTagalysParentCategories();
        $tagalysLegacyCategories = $this->tagalysConfiguration->getConfig('legacy_mpage_categories', true);
        $tagalysCreated = array_merge($tagalysCreated, $tagalysLegacyCategories);
        $categories = $this->categoryCollectionFactory->create()->addAttributeToSelect('entity_id');
        foreach($categories as $category){
            if(in_array($category->getParentId(), $tagalysCreated)){
                $tagalysCreated[] = $category->getId();
            }
        }
        return $tagalysCreated;
    }

    public function getTagalysParentCategory($storeId){
        $rootCategoryId = $this->storeManagerInterface->getStore($storeId)->getRootCategoryId();
        $categoryId = $this->tagalysConfiguration->getConfig("tagalys_parent_category_for_root_$rootCategoryId");
        $categoryId = $this->categoryFactory->create()->load($categoryId)->getId();
        return $categoryId;
    }

    public function getAllTagalysParentCategories(){
        $categoryId = [];
        $stores = $this->tagalysConfiguration->getStoresForTagalys();
        foreach($stores as $sid){
            $catId = $this->getTagalysParentCategory($sid);
            if($catId != null){
                $categoryId[] = $catId;
            }
        }
        return $categoryId;
    }

    public function setTagalysParentCategory($storeId, $categoryId) {
        // tagalys_parent_category_for_root_ was previously tagalys_parent_category_store_$storeId
        // migrate the old clients
        $rootCategoryId = $this->storeManagerInterface->getStore($storeId)->getRootCategoryId();
        $categoryId = $this->tagalysConfiguration->setConfig("tagalys_parent_category_for_root_$rootCategoryId", $categoryId);
    }

    public function redirectToCategoryUrl($storeId, $categoryId, $path){
        $category = $this->categoryFactory->create()->load($categoryId);
        $urlPath = $category->getUrlPath();
        if( substr($category->getUrl(), -5) === '.html' ){
            $urlPath.='.html';
        }
        $this->createUrlRewrite($storeId, $path, $urlPath, 301);
    }

    private function createUrlRewrite($storeId, $requestPath, $targetPath, $redirectType) {
        $urlRewriteModel = $this->urlRewriteFactory->create();
        $urlRewriteModel->setStoreId($storeId);
        $urlRewriteModel->setIsSystem(0);
        $urlRewriteModel->setRequestPath($requestPath);
        $urlRewriteModel->setTargetPath($targetPath);
        $urlRewriteModel->setRedirectType($redirectType);
        $urlRewriteModel->setDescription("Created by Tagalys");
        $urlRewriteModel->save();
    }

    public function updateCategoryUrlRewrite($storeId, $categoryId, $path){
        $targetPath = "catalog/category/view/id/$categoryId";
        $urlRewrite = $this->urlRewriteCollection->addStoreFilter($storeId)->addFieldToFilter('target_path', $targetPath)->addFieldToFilter('is_autogenerated', 1)->getFirstItem();
        $urlRewrite->setRequestPath($path);
        $urlRewrite->save();
    }

    private function isLegacyMpageCategory($category){
        $parentId = $category->getParentId();
        $legacyCategories = $this->tagalysConfiguration->getConfig('legacy_mpage_categories', true);
        return in_array($parentId, $legacyCategories);
    }

    public function reindexFlatCategories() {
        try{
            $categoryFlatIndexer = $this->indexerFactory->create()->load('catalog_category_flat');
            $categoryFlatIndexer->reindexAll();
        } catch(\Exception $e) {
            $this->logger->err("reindexFlatCategories: {$e->getMessage()}");
        }
    }

    public function getStoresForCategory($categoryId){
        //NOTE: does not check if category is enabled/disabled status
        $storeIds = [];
        $stores = $this->storeManagerInterface->getStores();
        $category = $this->categoryFactory->create()->load($categoryId);
        foreach ($stores as $storeId => $store) {
            $rootCategoryId = $store->getRootCategoryId();
            $path = explode('/', $category->getPath());
            if (in_array($rootCategoryId, $path)){
                $storeIds[] = $storeId;
            }
        }
        return $storeIds;
    }

    public function canDelete($categoryId){
        $activeStores = $this->getCategoryActiveStores($categoryId);
        if(count($activeStores) == 0){
            return true;
        }
        return false;
    }

    public function getCategoryActiveStores($categoryId){
        $activeStores = [];
        $stores = $this->storeManagerInterface->getStores();
        foreach ($stores as $storeId => $store) {
            $category = $this->categoryFactory->create()->setStoreId($storeId)->load($categoryId);
            if ($category->getIsActive() == '1'){
                $activeStores[] = $storeId;
            }
        }
        return $activeStores;
    }

    public function categoryExist($categoryId){
        $categoryId = $this->categoryFactory->create()->load($categoryId)->getId();
        if ($categoryId){
            return true;
        }
        return false;
    }

    public function getIndexTableName($storeId) {
        $beforeM225 = (version_compare($this->productMetadataInterface->getVersion(), '2.2.5') < 0);
        if ($beforeM225) {
            return $this->resourceConnection->getTableName('catalog_category_product_index');
        } else {
            return ($this->resourceConnection->getTableName("catalog_category_product_index_store$storeId"));
        }
    }
}