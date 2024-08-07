<?php
namespace Tagalys\Sync\Helper;

class Category extends \Magento\Framework\App\Helper\AbstractHelper
{
    const PLATFORM_CREATED = 'platform_created';
    const TAGALYS_CREATED = 'tagalys_created';

    private $tagalysConfiguration;
    private $random;
    private $_registry;
    private $tagalysApi;
    private $categoryCollection;
    private $categoryCollectionFactory;
    private $tagalysCategoryFactory;
    private $productFactory;
    private $categoryFactory;
    private $storeManagerInterface;
    private $resourceConnection;
    private $resourceModelIterator;
    private $categoryProductLinkInterfaceFactory;
    private $categoryLinkRepositoryInterface;
    private $cacheInterface;
    private $eventManager;
    private $productMetadataInterface;
    private $indexerFactory;
    private $urlRewriteFactory;
    private $urlRewriteCollection;
    private $auditLog;
    private $restrictedAction;
    private $tagalysQueue;
    private $logger;

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
        \Magento\UrlRewrite\Model\ResourceModel\UrlRewriteCollection $urlRewriteCollection,
        \Tagalys\Sync\Helper\AuditLog $auditLog,
        \Tagalys\Sync\Helper\RestrictedAction $restrictedAction
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
        // Todo: use event manager interface instead?
        $this->eventManager = $eventManager;
        $this->productMetadataInterface = $productMetadataInterface;
        $this->indexerFactory = $indexerFactory;
        $this->urlRewriteFactory = $urlRewriteFactory;
        $this->urlRewriteCollection = $urlRewriteCollection;
        $this->auditLog = $auditLog;
        $this->restrictedAction = $restrictedAction;
        $this->tagalysQueue = $tagalysQueue;

        $this->restrictedAction->setNamespace("audit_log_transfer");
        $logLevel = $this->tagalysConfiguration->getLogLevel();
        $this->logger = Utils::getLogger("tagalys_categories.log", $logLevel);
    }

    public function truncate() {
        $tagalysCategoryFactory = $this->tagalysCategoryFactory->create();
        $connection = $tagalysCategoryFactory->getResource()->getConnection();
        $tableName = $tagalysCategoryFactory->getResource()->getMainTable();
        $connection->truncateTable($tableName);
    }

    public function createOrUpdateWithData($storeId, $categoryId, $data, $updateData = null)
    {
        $record = $this->tagalysCategoryFactory->create()->getCollection()
            ->addFieldToFilter('category_id', $categoryId)
            ->addFieldToFilter('store_id', $storeId)
            ->getFirstItem();
        try {
            if ($record->getId()) {
                if (!empty($updateData)) {
                    $data = $updateData;
                }
            } else {
                $record = $this->tagalysCategoryFactory->create();
            }
            $data['category_id'] = $categoryId;
            $data['store_id'] = $storeId;
            $record->addData($data)->save();
            return $record->getId();
        } catch (\Exception $e) {}
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
        } catch (\Exception $e) {
            return false;
        }
    }
    public function deleteCategoryEntries($storeId, $categoryId){
        $tagalysCategories = $this->tagalysCategoryFactory->create()->getCollection()
            ->addFieldToFilter('category_id', $categoryId);
        if($storeId != null){
            $tagalysCategories->addFieldToFilter('store_id', $storeId);
        }
        foreach ($tagalysCategories as $tagalysCategory) {
            $tagalysCategory->delete();
        }
    }
    public function markStoreCategoryIdsForDeletionExcept($storeId, $categoryIds) {
        $collection = $this->tagalysCategoryFactory->create()->getCollection()->addFieldToFilter('store_id', $storeId);
        foreach ($collection as $collectionItem) {
            $categoryId = $collectionItem->getCategoryId();
            if (!in_array((int)$categoryId, $categoryIds)) {
                if ($this->isTagalysCreated($categoryId)) {
                    continue;
                }
                $collectionItem->addData(array('marked_for_deletion' => 1))->save();
            }
        }
    }

    public function markStoreCategoryIdsToDisableExcept($storeId, $categoryIds){
        $collection = $this->tagalysCategoryFactory->create()
            ->getCollection()
            ->addFieldToFilter('store_id', $storeId);
        if(count($categoryIds) > 0) {
            $collection->addFieldToFilter('category_id', ['nin' => $categoryIds]);
        }
        foreach ($collection as $collectionItem) {
            if (!$this->isTagalysCreated($collectionItem->getCategoryId())) {
                $collectionItem->setStatus('pending_disable')->save();
            }
        }
    }

    public function platformHasMultipleStores()
    {
        $allStores = $this->tagalysConfiguration->getAllWebsiteStores();
        if (count($allStores) > 1) {
            return true;
        }
        return false;
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
            $this->createOrUpdateWithData($storeId, $categoryId, array('positions_sync_required' => 0, 'marked_for_deletion' => 0, 'status' => 'pending_sync'));
            }
        }
        $this->storeManagerInterface->setCurrentStore($originalStoreId);
        }
    }

    public function maintenanceSync()
    {
        // once a day
        $listingPagesEnabled = ($this->tagalysConfiguration->getConfig("module:listingpages:enabled") != '0');
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
        if (empty($syncStatus) || !array_key_exists('locked_by', $syncStatus) || $syncStatus['locked_by'] == null) {
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
    public function getRequiresPositionsSyncCollection($idsFilter = false)
    {
        $categoriesToSync = $this->tagalysCategoryFactory->create()->getCollection()
            ->addFieldToFilter('status', 'powered_by_tagalys')
            ->addFieldToFilter('positions_sync_required', 1)
            ->addFieldToFilter('marked_for_deletion', 0);
        if($idsFilter != false) {
            $categoriesToSync->addFieldToFilter('id', $idsFilter);
        }
        return $categoriesToSync;
    }

    private function setTagalysContextIfRequired() {
        if (is_null($this->_registry->registry("tagalys_context"))) {
            $this->_registry->register("tagalys_context", true);
        }
    }

    public function updatePositionsIfRequired($maxProductsPerCronRun = null) {
        $this->setTagalysContextIfRequired();
        $listingPagesEnabled = ($this->tagalysConfiguration->getConfig("module:listingpages:enabled") != '0');
        if ($listingPagesEnabled) {
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
                if (is_null($maxProductsPerCronRun)) {
                    $maxProductsPerCronRun = $this->tagalysConfiguration->getConfig('listing_pages:max_categories_per_cron');
                }
                $collection = $this->getRequiresPositionsSyncCollection()->setPageSize($maxProductsPerCronRun);
                $collectionIds = Utils::getAllIds($collection);
                $perPage = $this->tagalysConfiguration->getConfig('listing_pages:categories_per_page');
                $perPage = min($perPage, $maxProductsPerCronRun);
                Utils::forEachChunk($collectionIds, $perPage, function ($idsChunk) {
                    $categoriesToSync = $this->getRequiresPositionsSyncCollection($idsChunk);
                    $utcNow = new \DateTime("now", new \DateTimeZone('UTC'));
                    $timeNow = $utcNow->format(\DateTime::ATOM);
                    $syncStatus['updated_at'] = $timeNow;
                    $this->tagalysConfiguration->setConfig('categories_sync_status', $syncStatus, true);
                    foreach ($categoriesToSync as $categoryToSync) {
                        $storeId = $categoryToSync->getStoreId();
                        $categoryId = $categoryToSync->getCategoryId();
                        try {
                            $response = $this->tagalysApi->storeApiCall($storeId . '', "/v1/mpages/_product_positions", ['id_at_platform' => $categoryId]);
                            if ($response != false) {
                                $category = $this->categoryFactory->create()->load($categoryId);
                                if($this->categoryExist($category)) {
                                    if($this->isTagalysManaged($storeId, $category)) {
                                        $this->bulkAssignProductsToCategoryAndRemove($storeId, $categoryId, $response['positions']);
                                    } else {
                                        $this->performCategoryPositionUpdate($storeId, $categoryId, $response['positions']);
                                    }
                                } else {
                                    $categoryToSync->addData(['positions_sync_required' => 0, 'marked_for_deletion' => 1])->save();
                                }
                            } else {
                                $categoryToSync->setPositionsSyncRequired(0)->save();
                            }
                        } catch (\Throwable $e) {
                            $categoryToSync->setPositionsSyncRequired(0)->save();
                            $errorData = [
                                'store_id' => $storeId,
                                'message' => $e->getMessage(),
                                'trace' => $e->getTrace(),
                                'file' => $e->getFile(),
                                'line' => $e->getLine()
                            ];
                            $this->tagalysApi->log('error', "Exception in updatePositionsIfRequired: category: $categoryId", $errorData);
                        }
                    }
                });
                $syncStatus['locked_by'] = null;
                $this->tagalysConfiguration->setConfig('categories_sync_status', $syncStatus, true);
            }
        }
    }

    public function _setPositionSortOrder($storeId, $categoryId) {
        $mappedStoreIds = $this->tagalysConfiguration->getMappedStores($storeId, true);
        foreach ($mappedStoreIds as $mappedStoreId) {
            $category = $this->loadCategoryForUpdate($mappedStoreId, $categoryId);
            if ($category->getDefaultSortBy() != 'position') {
                $category->setDefaultSortBy('position')->save();
            }
        }
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

    public function getCategoryUrl($category) {
        $categoryUrl = $category->getUrl();
        $unSecureBaseUrl = $this->storeManagerInterface->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB, false);
        if (strpos($categoryUrl, $unSecureBaseUrl) === 0) {
            $secureBaseUrl = $this->storeManagerInterface->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB, true);
            $categoryUrl = substr_replace($categoryUrl, $secureBaseUrl, 0, strlen($unSecureBaseUrl));
        }
        return $categoryUrl;
    }
    public function getStoreCategoryDetails($storeId, $categoryId) {
        try {
            $output = null;
            $this->tagalysConfiguration->processInStoreContext($storeId, function () use($categoryId, &$output) {
                $category = null;
                $category = $this->categoryFactory->create()->load($categoryId);
                $categoryActive = ($category->getIsActive() == '1');
                $path = explode('/', $category->getPath());
                $ancestry = array_slice($path, 2, -1);
                $output = array(
                    "id" => "__categories-$categoryId",
                    "slug" => $this->getCategoryUrl($category),
                    "path" => $category->getUrlPath(),
                    'ancestry' => $ancestry,
                    "is_active" => $categoryActive,
                    'include_in_menu' => ($category->getIncludeInMenu() == '1'),
                    "name" => implode(' / ', array_slice(explode(' |>| ', $this->tagalysConfiguration->getCategoryName($category)), 1)),
                    "filters" => array(
                        array(
                            "field" => "__categories",
                            "tag_jsons" => [json_encode(['id' => $category->getId(), 'name' => $category->getName(), 'ancestry' => $ancestry])]
                        ),
                        array(
                            "field" => "visibility",
                            "tag_jsons" => array("{\"id\":\"2\",\"name\":\"Catalog\"}", "{\"id\":\"4\",\"name\":\"Catalog, Search\"}")
                        )
                    )
                );
            });
            return $output;
        } catch (\Exception $e) {
            return false;
        }
    }
    public function sync($max = null)
    {

        $this->tagalysConfiguration->updateTagalysHealth();

        if($this->tagalysConfiguration->getConfig('audit_logs:enabled', true, true)) {
            $this->restrictedAction->tryExecute(function() {
                $this->auditLog->syncToTagalys();
            });
        }

        if($max == null) {
            $max = (int) $this->tagalysConfiguration->getConfig("sync:max_categories_per_cron");
        }
        $listingPagesEnabled = ($this->tagalysConfiguration->getConfig("module:listingpages:enabled") == '1');
        $powerAllListingPages = ($this->tagalysConfiguration->getConfig("module:listingpages:enabled") == '2');
        if($powerAllListingPages){
            $this->powerAllCategories();
            $listingPagesEnabled = true;
        }
        if ($listingPagesEnabled) {
            $detailsToSync = array();

            // save
            $categoriesToSync = $this->tagalysCategoryFactory->create()->getCollection()
                ->addFieldToFilter('status', 'pending_sync')
                ->addFieldToFilter('marked_for_deletion', 0)
                ->setPageSize($max);
            foreach ($categoriesToSync as $i => $categoryToSync) {
                $categoryId = $categoryToSync->getCategoryId();
                if($this->isTagalysCreated($categoryId)){
                    $categoryToSync->setStatus('powered_by_tagalys')->save();
                } else {
                    $storeId = $categoryToSync->getStoreId();
                    $payload = $this->getStoreCategoryDetails($storeId, $categoryToSync->getCategoryId());
                    if ($payload === false) {
                        $categoryToSync->setStatus('failed')->save();
                    } else {
                        array_push($detailsToSync, array('perform' => 'save', 'store_id' => $storeId, 'payload' => $payload));
                    }
                }
            }
            // disable
            $categoriesToDisable = $this->tagalysCategoryFactory->create()->getCollection()
                ->addFieldToFilter('status', 'pending_disable')
                ->addFieldToFilter('marked_for_deletion', 0)
                ->setPageSize($max);
            foreach ($categoriesToDisable as $i => $categoryToDisable) {
                $storeId = $categoryToDisable->getStoreId();
                $categoryId = $categoryToDisable->getCategoryId();
                array_push($detailsToSync, array('perform' => 'disable', 'store_id' => $storeId, 'payload' => array('id_at_platform' => $categoryId)));
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
                $request = array('actions' => $detailsToSync);
                $tagalysResponse = $this->tagalysApi->clientApiCall('/v1/mpages/_sync_platform_pages', $request);

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
                    foreach ($categoriesToDisable as $i => $categoryToDisable) {
                        $categoryToDisable->delete();
                    }
                }
            }
        }
    }

    public function assignParentCategoriesToAllProducts($viaDb = false){
        return false;
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
        } catch (\Exception $e) {
            return false;
        }
    }
    public function pushDownProductsIfRequired($productIds, $productCategories = null, $indexerToRun = 'product') {
        // called from observers when new products are added to categories - in position ascending order, they should be positioned at the bottom of the page.
        $listingpagesEnabled = $this->tagalysConfiguration->getConfig('module:listingpages:enabled');
        if($listingpagesEnabled != '0') {
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
                                // cache may already be getting updated. even otherwise, we don't need to update here as we don't want products to show up anyway. caches are cleared when positions are updated via Tagalys.
                                $this->reindexUpdatedProducts($productIds);
                                break;
                            case 'category':
                                $this->reindexCategoryProducts($tagalysCategories);
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

    public function getTagalysManagedCategories() {
        $tagalysManagedCategories = $this->tagalysCategoryFactory->create()->getCollection()->addFieldToFilter('tagalys_managed_products', true);
        $tagalysCategories = array();
        foreach ($tagalysManagedCategories as $item) {
            $tagalysCategories[] = $item->getCategoryId();
        }
        $tagalysCategories = array_unique($tagalysCategories);
        return $tagalysCategories;
    }

    public function performCategoryPositionUpdate($storeId, $categoryId, $positions) {
        $this->logger->info("performCategoryPositionUpdate: store_id: $storeId, category_id: $categoryId, productPositions count: " . count($positions));
        $this->auditLog->logInfo("CategoryHelper::performCategoryPositionUpdate", "Performing position updated for storeId: $storeId, categoryId: $categoryId", ['positions' => $positions]);
        if ($this->tagalysConfiguration->isProductSortingReverse()) {
            $positions = $this->reverseProductPositionsHash($positions);
        }
        $updateViaDb = $this->tagalysConfiguration->getConfig('listing_pages:update_position_via_db_for_mcc', true);
        $considerMultiStore = $this->tagalysConfiguration->getConfig('listing_pages:consider_multi_store_during_position_updates', true);
        if($updateViaDb || $considerMultiStore){
            $this->pushDownProductsViaDb($storeId, $categoryId, $positions, $considerMultiStore);
        }
        if ($updateViaDb){
            $this->_updatePositionsViaDb($categoryId, $positions);
        } else {
            $this->_updatePositions($storeId, $categoryId, $positions, !$considerMultiStore);
        }
        $this->_setPositionSortOrder($storeId, $categoryId);
        $this->updateWithData($storeId, $categoryId, ['positions_sync_required' => 0, 'positions_synced_at' => date("Y-m-d H:i:s"), 'status' => 'powered_by_tagalys']);
        $this->reindexCategoryProducts($categoryId, self::PLATFORM_CREATED);
        return true;
    }

    public function loadCategoryForUpdate($storeId, $categoryId) {
        $category = $this->categoryFactory->create()->setStoreId($storeId)->load($categoryId);

        if($this->tagalysConfiguration->getConfig("apply_category_use_default_uncheck_fix", true, true)) {
            // Assign NULL for all the attributes that are not overridden at the store level.
            // Set `data['use_default'][key] = true` for the attributes that are not overridden
            // If this is not done, the use default check box was getting unchecked for all the attributes at the store level
            $populateWithValues = Utils::getInstanceOf('\Magento\Catalog\Model\CategoryRepository\PopulateWithValues');
            $populateWithValues->execute($category, ['store_id' => $storeId]);
        }

        return $category;
    }

    public function _updatePositions($storeId, $categoryId, $newPositions, $canPushDown) {
        $category = $this->loadCategoryForUpdate($storeId, $categoryId);
        $positions = $category->getProductsPosition();
        $productCount = count($positions);
        foreach ($positions as $productId => $position) {
            if (array_key_exists($productId, $newPositions)) {
                $positions[$productId] = $newPositions[$productId];
            } else if($canPushDown) {
                if ($this->tagalysConfiguration->isProductSortingReverse()) {
                    $positions[$productId] = 0;
                } else {
                    $positions[$productId] = $productCount + 1;
                }
            }
        }
        $category->setPostedProducts($positions)->save();
    }

    public function _updatePositionsViaDb($categoryId, $positions) {
        foreach ($positions as $productId => $productPosition) {
            $whereData = array(
                'category_id = ?' => (int) $categoryId,
                'product_id = ?' => (int) $productId
            );
            $updateData = array(
                'position' => (int) $productPosition
            );
            $this->runSqlForCategoryPositions($updateData, $whereData);
        }
        return true;
    }

    public function pushDownProductsViaDb($storeId, $categoryId, $positions, $considerMultiStore) {
        $desc = $this->tagalysConfiguration->isProductSortingReverse();
        $indexTable = $this->getIndexTableName($storeId);
        $ccp = $this->resourceConnection->getTableName('catalog_category_product');
        $total = count($positions);
        $positionCondition = $desc ? "position >= 100" : "position <= $total";
        $pushDownPosition = $desc ? 99 : $total + 1;
        if ($considerMultiStore) {
            $sql = "UPDATE $ccp SET position = $pushDownPosition WHERE category_id = $categoryId AND $positionCondition AND product_id IN (SELECT DISTINCT product_id FROM $indexTable WHERE category_id = $categoryId AND $positionCondition AND store_id = $storeId AND visibility IN (2, 4));";
        } else {
            $sql = "UPDATE $ccp SET position = $pushDownPosition WHERE category_id = $categoryId AND $positionCondition;";
        }
        $this->runSql($sql);
    }

    public function reverseProductPositionsHash($positions){
        $reversedPositions = [];
        $maxPosition = count($positions) + 100;
        foreach ($positions as $productId => $position) {
            $reversedPositions[$productId] = $maxPosition - $position;
        }
        return $reversedPositions;
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
        foreach ($forStores as $storeId) {
            $this->updateCategoryDetails($categoryId, ['is_active'=> true], $storeId);
        }
        return $categoryId;
    }

    public function updateCategoryDetails($categoryId, $categoryDetails, $forStores) {
        $this->logger->info("updateCategoryDetails: category_id: $categoryId, categoryDetails: " . json_encode([$categoryDetails, $forStores]));
        if(!is_array($forStores)){
            $forStores = [$forStores];
        }
        $category = $this->categoryFactory->create()->setStoreId(0)->load($categoryId);
        if($category->getId() == null){
            throw new \Exception("Platform category not found");
        }
        $categoryDetails['default_sort_by'] = 'position';
        foreach ($forStores as $storeId) {
            // TODO: Use loadCategoryForUpdate to avoid overwriting the use default checkbox values
            $category = $this->categoryFactory->create()->setStoreId($storeId)->load($categoryId);
            $category->addData($categoryDetails)->save();
            $parentCategoryId = $this->getTagalysParentCategory($storeId);
             if($parentCategoryId != $categoryId && $this->tagalysConfiguration->isPrimaryStore($storeId)){
                if($category->getIsActive() == '1'){
                    $this->createOrUpdateWithData($storeId, $categoryId, ['positions_sync_required' => 1, 'status' => 'powered_by_tagalys']);
                    $this->logger->info("enabling category: $categoryId for store: $storeId");
                } else {
                    $this->deleteCategoryEntries($storeId, $categoryId);
                    $this->logger->info("disabling category: $categoryId for store: $storeId");
                }
            }
        }
        $this->reindexFlatCategories();
        $this->categoryUpdateAfter($category);
        return $categoryId;
    }

    public function categoryUpdateAfter($category){
        // code related to js mpage to new page migration
        // if($this->isLegacyMpageCategory($category)){
        //     $this->logger->info("categoryUpdateAfter: Updating url_rewrite for legacy mpage category, category_id: ".$category->getId());
        //     $urlRewrite = $this->urlRewriteCollection->addFieldToFilter('target_path', "catalog/category/view/id/{$category->getId()}")->getFirstItem();
        //     $urlRewrite->setRequestPath("m/{$category->getUrlKey()}")->save();
        // }
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
            $this->deleteCategoryEntries(null, $categoryId);
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
        $this->logger->info("bulkAssignProductsToCategoryAndRemove: store_id: $storeId, category_id: $categoryId, productPositions count: " . count($productPositions));
        if($this->isTagalysManaged($storeId, $categoryId)){
            $this->auditLog->logInfo("CategoryHelper::bulkAssignProductsToCategoryAndRemove", "Performing product assignment and position updates for store_id: $storeId, category_id: $categoryId", ['positions' => $productPositions]);
            if ($this->tagalysConfiguration->isProductSortingReverse()) {
                $productPositions = $this->reverseProductPositionsHash($productPositions);
            }
            $productPositions = $this->filterDeletedProducts($productPositions);
            $updateSmartCategoryProductsViaDb = $this->tagalysConfiguration->getConfig('listing_pages:update_position_via_db_for_tcc', true);
            $considerMultiStore = $this->tagalysConfiguration->getConfig('listing_pages:consider_multi_store_during_position_updates', true);
            if($updateSmartCategoryProductsViaDb){
                $productsToRemove = $this->getProductsToRemove($storeId, $categoryId, $productPositions, $considerMultiStore);
                $this->paginateSqlInsert($categoryId, $productPositions);
                $this->_paginateSqlRemove($categoryId, $productsToRemove);
            } else {
                $this->loadCategoryForUpdate($storeId, $categoryId)->setPostedProducts($productPositions)->save();
            }
            $this->_setPositionSortOrder($storeId, $categoryId);
            $this->updateWithData($storeId, $categoryId, ['positions_sync_required' => 0, 'positions_synced_at' => date("Y-m-d H:i:s"), 'status' => 'powered_by_tagalys']);
            $this->reindexCategoryProducts($categoryId, self::TAGALYS_CREATED);
            return true;
        }
        throw new \Exception("Error: this category wasn't created by Tagalys");
    }

    public function getProductPositionHash($productsArray){
        $productsHash = [];
        foreach ($productsArray as $index => $productId) {
            $productsHash[$productId] = $index + 1;
        }
        return $productsHash;
    }

    public function getProductsToRemove($storeId, $categoryId, $newProducts, $considerMultiStore){
        $categoryProducts = $this->getCurrentProducts($storeId, $categoryId, $considerMultiStore);
        $productsToRemove = [];
        foreach ($categoryProducts as $pid) {
            if(!array_key_exists($pid, $newProducts)){
                $productsToRemove[] = $pid;
            }
        }
        return $productsToRemove;
    }

    public function getProductVisibilityAttrId(){
        $ea = $this->resourceConnection->getTableName('eav_attribute');
        $eet = $this->resourceConnection->getTableName('eav_entity_type');
        $sql = "SELECT ea.attribute_id FROM $ea as ea INNER JOIN $eet as eet ON ea.entity_type_id = eet.entity_type_id WHERE eet.entity_table = 'catalog_product_entity' AND ea.attribute_code = 'visibility'";
        $rows = $this->runSqlSelect($sql);
        return $rows[0]['attribute_id'];
    }

    public function getCurrentProducts($storeId, $categoryId, $considerMultiStore){
        $productsInCategory = [];
        $ccp = $this->resourceConnection->getTableName('catalog_category_product');
        $useNewSqlForRemove = $this->tagalysConfiguration->getConfig('listing_pages:use_new_sql_for_product_remove', true);
        if($considerMultiStore) {
            if($useNewSqlForRemove) {
                $attributeId = $this->getProductVisibilityAttrId();
                $cpe = $this->resourceConnection->getTableName('catalog_product_entity');
                $cpei = $this->resourceConnection->getTableName('catalog_product_entity_int');
                $columnToJoin = $this->tagalysConfiguration->getResourceColumnToJoin();
                $sql = "SELECT cpe.entity_id as product_id, cpei.store_id, cpei.value as visibility FROM $cpe as cpe INNER JOIN $cpei as cpei ON cpe.{$columnToJoin} = cpei.{$columnToJoin} WHERE cpei.attribute_id = $attributeId AND cpei.store_id IN (0, $storeId) AND cpe.entity_id IN (SELECT product_id FROM $ccp WHERE category_id = $categoryId) ORDER BY cpei.store_id DESC";
                $rows = $this->runSqlSelect($sql);
                $productsWithVisibility = $this->getUniqueRows('product_id', $rows);
                // Todo: try using partial joins or grouping to filter the values in SQL itself
                $productsInCategory = array_values(array_filter($productsWithVisibility, function($row) {
                    return in_array($row['visibility'], ['2', '4']);
                }));
            } else {
                $indexTable = $this->getIndexTableName($storeId);
                $sql = "SELECT product_id, visibility FROM $indexTable WHERE category_id = $categoryId AND store_id = $storeId AND visibility IN (2, 4); ";
                $productsInCategory = $this->runSqlSelect($sql);
            }
        } else {
            $sql = "SELECT product_id FROM $ccp WHERE category_id = $categoryId;";
            $productsInCategory = $this->runSqlSelect($sql);
        }
        return array_map(function($row) {
            return $row['product_id'];
        }, $productsInCategory);
    }

    public function getUniqueRows($key, $rows) {
        $uniqueRows = [];
        $found = [];
        foreach ($rows as $row) {
            if (!array_key_exists($row[$key], $found)) {
                $found[$row[$key]] = true;
                $uniqueRows[] = $row;
            }
        }
        return $uniqueRows;
    }

    private function paginateSqlInsert($categoryId, $productPositions) {
        if (count($productPositions) > 0) {
            $productsInCategory = [];
            $rowsToInsert = [];
            $tableName = $this->resourceConnection->getTableName('catalog_category_product');
            $sql = "SELECT product_id FROM $tableName WHERE category_id=$categoryId;";
            $rows = $this->runSqlSelect($sql);
            foreach ($rows as $row) {
                $productsInCategory[] = $row['product_id'];
            }
            foreach ($productPositions as $productId => $position) {
                if (in_array($productId, $productsInCategory)) {
                    // product is already in this category, update position
                    $updateSql = "UPDATE $tableName SET position=$position WHERE category_id=$categoryId AND product_id=$productId;";
                    $this->runSql($updateSql);
                } else {
                    // to be inserted into the category with the correct position
                    $rowsToInsert[] = "($categoryId, $productId, $position)";
                }
            }
            $insertBatch = array_splice($rowsToInsert, 0, 500);
            while(count($insertBatch) > 0){
                $insertSql = "INSERT INTO $tableName (category_id, product_id, position) VALUES ";
                $values = implode(', ', $insertBatch);
                $query = $insertSql . $values . ';';
                $this->runSql($query);
                $insertBatch = array_splice($rowsToInsert, 0, 500);
            }
        }
    }

    public function filterDeletedProducts($productPositions){
        $cpe = $this->resourceConnection->getTableName('catalog_product_entity');
        $productInSystem = [];
        $deletedProducts = [];
        $productIds = array_flip($productPositions);
        $thisBatch = array_splice($productIds, 0, 500);
        while(count($thisBatch) > 0){
            $thisBatch = implode(',', $thisBatch);
            $sql = "SELECT entity_id FROM $cpe WHERE entity_id IN ($thisBatch)";
            $rows = $this->runSqlSelect($sql);
            foreach ($rows as $row) {
                $productInSystem[] = $row['entity_id'];
            }
            $thisBatch = array_splice($productIds, 0, 500);
        }
        foreach ($productPositions as $productId => $position) {
            if(!in_array($productId, $productInSystem)){
                $deletedProducts[] = $productId;
                unset($productPositions[$productId]);
            }
        }
        // add deleted product ids to sync queue
        $this->tagalysQueue->insertUnique($deletedProducts);
        return $productPositions;
    }

    private function _paginateSqlRemove($categoryId, $products){
        $perPage = 100;
        $offset = 0;
        $ccp = $this->resourceConnection->getTableName('catalog_category_product');
        $urpc = $this->resourceConnection->getTableName('catalog_url_rewrite_product_category');
        $ur = $this->resourceConnection->getTableName('url_rewrite');
        $productsToDelete = array_slice($products, $offset, $perPage);
        while(count($productsToDelete)>0){
            $productsToDelete = implode(', ', $productsToDelete);
            $sql = "DELETE FROM $ccp WHERE category_id=$categoryId AND product_id IN ($productsToDelete);";
            $this->runSql($sql);
            $sql = "DELETE FROM $ur WHERE url_rewrite_id IN (SELECT url_rewrite_id FROM $urpc WHERE category_id=$categoryId AND product_id IN ($productsToDelete))";
            $this->runSql($sql);
            $offset += $perPage;
            $productsToDelete = array_slice($products, $offset, $perPage);
        }
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

    public function reindexCategoryProducts($categoryIds, $categoryType = self::PLATFORM_CREATED, $force = false){
        if ($force || $this->tagalysConfiguration->isReindexAllowed($categoryType)) {
            if (!is_array($categoryIds)) {
                $categoryIds = [$categoryIds];
            }

            $categoryIds = array_unique($categoryIds);
            $this->indexerFactory->create()->load('catalog_category_product')->reindexList($categoryIds);

            $this->logger->info("reindexCategoryProducts: reindexed categoryIds: " . json_encode($categoryIds));

            if ($this->tagalysConfiguration->isCacheClearAllowed($categoryType)) {
                $this->clearCacheForCategories($categoryIds);
            }
            return true;
        }
        return false;
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
        $this->logger->info("clearCacheForCategories: cleared page cache for categoryIds: " . json_encode($categoryIds));
    }

    public function reindexUpdatedProducts($productIds) {
        if ($this->tagalysConfiguration->isReindexAllowed()) {
            if (!is_array($productIds)) {
                $productIds = [$productIds];
            }
            $indexer = $this->indexerFactory->create()->load('catalog_product_category');
            $indexer->reindexList($productIds);
            $this->logger->info("reindexUpdatedProducts: productIds: " . json_encode($productIds));
        }
    }

    public function isTagalysCreated($category) {
        if(!is_object($category)){
            $category = $this->categoryCollectionFactory->create()->addAttributeToSelect('parent_id')->addAttributeToFilter('entity_id', $category)->setPage(1,1)->getFirstItem();
        }
        if(empty($category->getId())){
            return false;
        }
        // one of the parent categories
        $parentCategories = $this->getAllTagalysParentCategories();
        if(in_array($category->getId(), $parentCategories)){
            return true;
        }
        // child of a Tagalys parent category
        $parentId = $category->getParentId();
        if(in_array($parentId, $parentCategories)){
            return true;
        }
        return false;
    }

    public function isTagalysManaged($storeId, $categoryId) {
        if (is_object($categoryId)) {
            $categoryId = $categoryId->getId();
        }
        if (empty($categoryId)) {
            return false;
        }
        $collection = $this->tagalysCategoryFactory->create()->getCollection()
            ->addFieldToFilter('store_id', $storeId)
            ->addFieldToFilter('category_id', $categoryId)
            ->addFieldToFilter('tagalys_managed_products', 1);
        return ($collection->getSize() > 0);
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

    public function getTagalysParentCategoryDetails($storeId){
        $categoryId = $this->getTagalysParentCategory($storeId);
        if(empty($categoryId)) {
            return null;
        }
        $category = $this->categoryFactory->create()->setStoreId($storeId)->load($categoryId);
        return [
            'parent_category_id' => $categoryId,
            'parent_category_name' => $category->getName(),
            'parent_category_url_key' => $category->getUrlKey(),
        ];
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
            $reindexFlatCategory = $this->tagalysConfiguration->getConfig('listing_pages:reindex_category_flat_after_updates', true);
            if($reindexFlatCategory){
                $this->indexerFactory->create()->load('catalog_category_flat')->reindexAll();
            }
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

    public function categoryExist($category){
        if(!is_object($category)) {
            $category = $this->categoryFactory->create()->load($category);
        }
        return !empty($category->getId());
    }

    public function getIndexTableName($storeId) {
        $beforeM225 = (version_compare($this->productMetadataInterface->getVersion(), '2.2.5') < 0);
        if ($beforeM225) {
            return $this->resourceConnection->getTableName('catalog_category_product_index');
        } else {
            return ($this->resourceConnection->getTableName("catalog_category_product_index_store$storeId"));
        }
    }

    public function getProductPosition($categoryId) {
        $ccp = $this->resourceConnection->getTableName('catalog_category_product');
        $sql = "SELECT product_id, position FROM $ccp WHERE category_id=$categoryId ORDER BY position";
        $positions = $this->runSqlSelect($sql);
        return $positions;
    }

    public function getProductPositionFromIndex($storeId, $categoryId) {
        $indexTable = $this->getIndexTableName($storeId);
        $sql = "SELECT product_id, position FROM $indexTable WHERE store_id=$storeId AND category_id=$categoryId ORDER BY position";
        $positions = $this->runSqlSelect($sql);
        return $positions;
    }

    public function powerCategoryForAllStores($category){
        $tagalysStores = $this->tagalysConfiguration->getStoresForTagalys();
        foreach ($tagalysStores as $storeId) {
            if($this->tagalysConfiguration->isPrimaryStore($storeId)){
                // Check if this category is available in this store
                $store = $this->storeManagerInterface->getStore($storeId);
                $storeRoot = $store->getRootCategoryId();
                $categoryRoot = explode('/', $category->getPath())[1];
                if($storeRoot == $categoryRoot){
                    // We do this even for disabled categories as the disabled categories will be marked as failed on sync and will be retried periodically during maintenance
                    $this->createOrUpdateWithData($storeId, $category->getId(), ['positions_sync_required' => 0, 'marked_for_deletion' => 0, 'status' => 'pending_sync']);
                }
            }
        }
    }

    public function powerAllCategoriesForStore($storeId){
        $categories = $this->tagalysConfiguration->getAllCategories($storeId);
        $storeRoot = $this->storeManagerInterface->getStore($storeId)->getRootCategoryId();
        foreach ($categories as $category) {
            $path = explode('/',$category['value']);
            if(in_array($storeRoot, $path)){
                $categoryId = end($path);
                $this->markCategoryForSyncIfRequired($storeId, $categoryId);
            }
        }
    }

    public function powerAllCategories(){
        $storesForTagalys = $this->tagalysConfiguration->getStoresForTagalys();
        foreach($storesForTagalys as $storeId){
            if ($this->tagalysConfiguration->isPrimaryStore($storeId)){
                $this->powerAllCategoriesForStore($storeId);
            }
        }
    }

    public function markCategoryForSyncIfRequired($storeId, $categoryId) {
        $firstItem = $this->tagalysCategoryFactory->create()->getCollection()
            ->addFieldToFilter('category_id', $categoryId)
            ->addFieldToFilter('store_id', $storeId)
            ->getFirstItem();
        if ($firstItem->getId()) {
            $firstItem->setMarkedForDeletion(0);
            if ($firstItem->getStatus() == 'pending_disable') {
                $firstItem->setStatus('pending_sync');
            }
        } else {
            $firstItem = $this->tagalysCategoryFactory->create();
            $firstItem->setData([
                'store_id' => $storeId,
                'category_id' => $categoryId,
                'positions_sync_required' => 0,
                'marked_for_deletion' => 0,
                'status' => 'pending_sync'
            ]);
        }
        $firstItem->save();
    }

    public function triggerCategorySync($storeId = false){
        $collection = $this->tagalysCategoryFactory->create()->getCollection();
        if($storeId){
            $collection->addFieldToFilter('store_id', $storeId);
        }
        foreach ($collection as $tagalysCategory) {
            if (!$this->isTagalysCreated($tagalysCategory->getCategoryId())) {
                $tagalysCategory->setStatus('pending_sync')->save();
            }
        }
        return $collection->count();
    }

    public function createOrUpdateWithRows($rows) {
        foreach($rows as $row) {
            $updateData = array_key_exists('update_data', $row) ? $row['update_data'] : false;
            $this->createOrUpdateWithData($row['store_id'], $row['category_id'], $row['data'], $updateData);
        }
    }

    public function markAsPositionSyncRequired($storeId, $categoryId, $manageProducts = null) {
        $updateData = ['positions_sync_required' => 1];
        if($manageProducts !== null) {
            $updateData['tagalys_managed_products'] = $manageProducts;
        }
        $this->updateWithData($storeId, $categoryId, $updateData);
    }

    public function markCategoryForDisable($categoryId) {
        $categories = $this->tagalysCategoryFactory->create()->getCollection()->addFieldToFilter('category_id', $categoryId);
        foreach($categories as $category) {
            $category->setStatus('pending_disable')->save();
        }
    }

    public function isPresentInTagalysCategoriesTable($categoryId) {
        $categories = $this->tagalysCategoryFactory->create()->getCollection()->addFieldToFilter('category_id', $categoryId);
        return ($categories->getSize() > 0);
    }

    public function getCategoryTag($category) {
        $categoryEnabled = (($category->getIsActive() === true || $category->getIsActive() === '1') ? true : false);
        $categoryIncludedInMenu = (($category->getIncludeInMenu() === true || $category->getIncludeInMenu() === '1') ? true : false);
        return [
            "id" => $category->getId(),
            "label" => $category->getName(),
            "is_active" => $categoryEnabled,
            "include_in_menu" => $categoryIncludedInMenu
        ];
    }
}
