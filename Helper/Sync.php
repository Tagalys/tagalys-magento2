<?php

namespace Tagalys\Sync\Helper;

use Tagalys\Sync\Exception\LockException;

class Sync extends \Magento\Framework\App\Helper\AbstractHelper
{
    private $tagalysConfiguration;
    private $tagalysApi;
    private $tagalysProduct;
    private $tagalysCategory;
    private $productFactory;
    private $random;
    private $urlInterface;
    private $storeManager;
    private $frontUrlHelper;
    private $queueFactory;
    private $queueHelper;
    private $resourceConnection;
    private $indexerFactory;
    private $syncRestrictedAction;
    private $filesystem;
    private $directory;
    private $maxProducts;
    private $maxProductsForUpdate;
    private $perPage;
    private $pid;

    const PRIORITY_UPDATES = 'priority_updates';
    const QUICK_FEED = 'quick_feed';

    public function __construct(
        \Magento\Framework\Filesystem $filesystem,
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration,
        \Tagalys\Sync\Helper\Api $tagalysApi,
        \Tagalys\Sync\Helper\Product $tagalysProduct,
        \Tagalys\Sync\Helper\Category $tagalysCategory,
        \Magento\Catalog\Model\ProductFactory $productFactory,
        \Magento\Framework\Math\Random $random,
        \Magento\Framework\UrlInterface $urlInterface,
        \Magento\Framework\Url $frontUrlHelper,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Tagalys\Sync\Model\QueueFactory $queueFactory,
        \Tagalys\Sync\Helper\Queue $queueHelper,
        \Magento\Framework\App\ResourceConnection $resourceConnection,
        \Magento\Indexer\Model\IndexerFactory $indexerFactory,
        \Tagalys\Sync\Helper\RestrictedAction $restrictedAction
    )
    {
        $this->tagalysConfiguration = $tagalysConfiguration;
        $this->tagalysApi = $tagalysApi;
        $this->tagalysProduct = $tagalysProduct;
        $this->tagalysCategory = $tagalysCategory;
        $this->productFactory = $productFactory;
        $this->random = $random;
        $this->urlInterface = $urlInterface;
        $this->storeManager = $storeManager;
        $this->frontUrlHelper = $frontUrlHelper;
        $this->queueFactory = $queueFactory;
        $this->queueHelper = $queueHelper;
        $this->resourceConnection = $resourceConnection;
        $this->indexerFactory = $indexerFactory;
        $this->syncRestrictedAction = $restrictedAction;

        $this->filesystem = $filesystem;
        $this->directory = $filesystem->getDirectoryWrite(\Magento\Framework\App\Filesystem\DirectoryList::MEDIA);

        $this->syncRestrictedAction->setNamespace("sync_cron");

        $this->maxProducts = 500;
        $this->maxProductsForUpdate = 500;
        $this->perPage = 50;

        $this->pid = $this->random->getRandomString(24);
    }

    public function triggerFeedForStore($storeId, $forceRegenerateThumbnails = false, $productsCount = false, $abandonIfExisting = false) {
        $feedStatus = $this->tagalysConfiguration->getConfig("store:$storeId:feed_status", true);
        if ($feedStatus == NULL || in_array($feedStatus['status'], array('finished')) || $abandonIfExisting) {
            $this->queueHelper->deleteByPriority(0, $storeId);
            $utcNow = new \DateTime("now", new \DateTimeZone('UTC'));
            $timeNow = $utcNow->format(\DateTime::ATOM);
            if ($productsCount == false) {
                $productsCount = $this->getProductsCount($storeId);
            }
            $feedStatus = $this->tagalysConfiguration->setConfig("store:$storeId:feed_status", json_encode(array(
                'status' => 'pending',
                'filename' => $this->_getNewSyncFileName($storeId, 'feed'),
                'products_count' => $productsCount,
                'completed_count' => 0,
                'updated_at' => $timeNow,
                'triggered_at' => $timeNow,
                'force_regenerate_thumbnails' => $forceRegenerateThumbnails
            )));
            $this->tagalysConfiguration->setConfig("store:$storeId:resync_required", '0');
            // triggerFeedForStore is generally called in a loop for all stores, so working without store context in sync:method:db.catalog_product_entity.updated_at:last_detected_change is safe

            $conn = $this->resourceConnection->getConnection();
            $conn->query("SET time_zone = '+00:00'");
            $tableName = $this->resourceConnection->getTableName('catalog_product_entity');
            $lastUpdatedAt = $conn->fetchAll("SELECT updated_at from $tableName ORDER BY updated_at DESC LIMIT 1")[0]['updated_at'];
            $this->tagalysConfiguration->setConfig("sync:method:db.catalog_product_entity.updated_at:last_detected_change", $lastUpdatedAt);
            return true;
        } else {
            return false;
        }
    }

    public function _getNewSyncFileName($storeId, $type) {
        $domain =  $this->_getDomain($storeId);
        $datetime = date("YmdHis");
        return "syncfile-$domain-$storeId-$type-$datetime.jsonl";
    }
    public function _getDomain($storeId) {
        $store = $this->storeManager->getStore($storeId);
        $baseUrl = $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB, true);
        $baseUrl = rtrim($baseUrl, '/');
        $exploded_1 = explode("://", $baseUrl);
        $replaced_1 = str_replace("-", "__", $exploded_1[1]);
        return str_replace("/", "___", $replaced_1);
    }

    public function getProductsCount($storeId) {
        return $this->_getCollection($storeId, 'feed')->getSize();
    }

    public function _getCollection($storeId, $type = 'feed', $productIdsFromUpdatesQueueForCronInstance = array()) {
        $websiteId = $this->storeManager->getStore($storeId)->getWebsiteId();
        $collection = $this->productFactory->create()->getCollection()
            // setting flag to include out of stock products: https://magento.stackexchange.com/questions/241709/how-to-get-product-collection-with-both-in-stock-and-out-of-stock-products-in-ma
            ->setFlag('has_stock_status_filter', false)
            ->setStoreId($storeId)
            ->addStoreFilter($storeId)
            ->addAttributeToFilter('status', 1)
            ->addAttributeToFilter('visibility', array("neq" => 1))
            ->addAttributeToSelect('*');
        if ($type == 'updates') {
            $collection = $collection->addAttributeToFilter('entity_id', array('in' => $productIdsFromUpdatesQueueForCronInstance));
        }
        if($this->tagalysConfiguration->getConfig("fallback:sync:add_price_data_to_product_collection", true, true)) {
            // calling addPriceData here was leading to "out of stock" products not being synced to Tagalys
            // reset website context - changed when using addFinalPrice() in addAssociatedProductDetails() and affects subsequent website store collection queries
            // the code causing the above mentioned problem will also run only if "fallback:sync:add_price_data_to_product_collection" is set to true
            $collection->addPriceData(null, $websiteId);
        }
        return $collection;
    }

    public function runMaintenance($force = false) {
        $stores = $this->tagalysConfiguration->getStoresForTagalys();
        foreach ($stores as $i => $storeId) {
            $periodic_full_sync = $this->tagalysConfiguration->getConfig("periodic_full_sync");
            $resync_required = $this->tagalysConfiguration->getConfig("store:$storeId:resync_required");
            if ($periodic_full_sync == '1' || $resync_required == '1' || $force) {
                $this->queueHelper->deleteByPriority(0);
                $this->deleteAllSyncFiles();
                $syncTypes = array('updates', 'feed');
                foreach ($syncTypes as $syncType) {
                    $syncTypeStatus = $this->tagalysConfiguration->getConfig("store:$storeId:" . $syncType . "_status", true);
                    $syncTypeStatus['status'] = 'finished';
                    $this->tagalysConfiguration->setConfig("store:$storeId:" . $syncType . "_status", $syncTypeStatus, true);
                }
                $this->tagalysConfiguration->setConfig("config_sync_required", '1');
                $this->triggerFeedForStore($storeId, false, false, true);
                $this->tagalysConfiguration->setConfig("store:$storeId:resync_required", '0');
            }
        }
        $this->tagalysCategory->maintenanceSync();
    }

    public function sync($maxProducts = null) {
        if($maxProducts == null) {
            $this->maxProducts = (int) $this->tagalysConfiguration->getConfig("sync:max_products_per_cron");
            $this->maxProductsForUpdate = (int) $this->tagalysConfiguration->getConfig("sync:max_products_per_cron_for_update");
        } else {
            $this->maxProducts = $maxProducts;
            $this->maxProductsForUpdate = $maxProducts;
        }
        $this->perPage = (int) $this->tagalysConfiguration->getConfig("sync:feed_per_page");
        $this->perPage = min($this->maxProducts, $this->perPage);
        $stores = $this->tagalysConfiguration->getStoresForTagalys();
        $avoidParallelSyncCrons = $this->tagalysConfiguration->getConfig("sync:avoid_parallel_sync_crons", true);
        if ($avoidParallelSyncCrons && !$this->syncRestrictedAction->lock()) {
            return false;
        }
        if ($stores != NULL) {

            // 2. update configuration if required
            $this->_checkAndSyncConfig();

            // perform priority updates and mini feed sync
            $cronUnlocked = $this->lockedCronOperation(function() use ($stores) {
                $this->runQuickFeedIfRequired($stores); // run the faster one first
                $this->runPriorityUpdatesIfRequired($stores);
            });
            if(!$cronUnlocked) {
                $cronStatus = $this->tagalysConfiguration->getConfig('cron_status', true);
                $lockedBy = $cronStatus['locked_by'];
                $this->tagalysApi->log('warn', "lockedCronOperation could not acquire lock. Locked by pid: $lockedBy", ['cron_status' => $cronStatus]);
                return false;
            }

            // 4. check updated_at if enabled
            $productUpdateDetectionMethods = $this->tagalysConfiguration->getConfig('product_update_detection_methods', true);
            if (in_array('db.catalog_product_entity.updated_at', $productUpdateDetectionMethods)) {
                // ! FIXME: creates entries with store_id as null, use insert unique internally to avoid that
                // migrateUpdatesQueueIfRequired will convert those into proper entries
                $this->queueHelper->importProductsToSync();
            }

            // migration step, set store_id value from entries in updates queue
            //* do not remove before fixing importProductsToSync
            $this->queueHelper->migrateUpdatesQueueIfRequired();

            // 6. perform feed, updates sync (updates only if feed sync is finished)
            foreach($stores as $i => $storeId) {
                if($this->shouldAbandonFeedAndUpdatesForStore($storeId)) {
                    $this->markFeedAsFinishedForStore($storeId);
                } else {
                    $this->truncateQueueAndTriggerSyncIfRequired($storeId);
                    $this->_syncForStore($storeId);
                }
            }
            $this->queueHelper->truncateIfEmpty();
        }
        if ($avoidParallelSyncCrons) {
            $this->syncRestrictedAction->unlock();
        }
        return true;
    }

    public function _checkAndSyncConfig() {
        $configSyncRequired = $this->tagalysConfiguration->getConfig('config_sync_required');
        if ($configSyncRequired == '1') {
            $response = $this->tagalysConfiguration->syncClientConfiguration();
            if ($response === false || $response['result'] === false) {
                $this->tagalysApi->log('error', 'syncClientConfiguration returned false', array());
            }
            $this->tagalysConfiguration->setConfig('config_sync_required', '0');
        }
    }

    public function _syncForStore($storeId) {
        try {
            $feedResponse = $this->_generateFilePart($storeId, 'feed');
            if($feedResponse == false) {
                // feedResponse will be false when shouldAbandonFeedAndUpdatesForStore becomes true for this store, while the sync is running.
                return true;
            }
            $syncFileStatus = $feedResponse['syncFileStatus'];
            if (!$this->_isFeedGenerationInProgress($storeId, $syncFileStatus)) {
                $productIdsForUpdate = $this->getProductIdsForUpdate($storeId);
                foreach ($productIdsForUpdate as $productId) {
                    $this->queueHelper->queuePrimaryProductIdFor($storeId, $productId);
                }
                if (count($productIdsForUpdate) > -1) {
                    $updatesResponse = $this->_generateFilePart($storeId, 'updates', $productIdsForUpdate);
                    if (isset($updatesResponse['updatesPerformed']) and $updatesResponse['updatesPerformed']) {
                        $this->queueHelper->paginateSqlDelete($productIdsForUpdate, 0, [$storeId]);
                    }
                }
            }
        } catch (LockException $e) {
            $this->tagalysApi->logExceptionToTagalys($e, "LockException during product sync", ['store_id' => $storeId]);
        }
    }

    public function _isFeedGenerationInProgress($storeId, $storeFeedStatus) {
        if ($storeFeedStatus == null) {
            return false;
        }
        if (in_array($storeFeedStatus['status'], array('finished'))) {
            return false;
        }
        return true;
    }

    public function _checkLock($syncFileStatus) {
        if (!array_key_exists('locked_by', $syncFileStatus) || $syncFileStatus['locked_by'] == null) {
            return true;
        } else {
            // some other process has claimed the thread. if a crash occours, check last updated at < 15 minutes ago and try again.
            $lockedAt = new \DateTime($syncFileStatus['updated_at']);
            $now = new \DateTime();
            $intervalSeconds = $now->getTimestamp() - $lockedAt->getTimestamp();
            $minSecondsForOverride = 10 * 60;
            if ($intervalSeconds > $minSecondsForOverride) {
                $this->tagalysApi->log('warn', 'Overriding stale locked process', array('pid' => $syncFileStatus['locked_by'], 'locked_seconds_ago' => $intervalSeconds));
                return true;
            } else {
                $this->tagalysApi->log('warn', 'Sync file generation locked by another process', array('pid' => $syncFileStatus['locked_by'], 'locked_seconds_ago' => $intervalSeconds));
                return false;
            }
        }
    }

    public function _reinitializeUpdatesConfig($storeId, $productIdsFromUpdatesQueueForCronInstance) {
        $utcNow = new \DateTime("now", new \DateTimeZone('UTC'));
        $timeNow = $utcNow->format(\DateTime::ATOM);
        $updatesCount = count($productIdsFromUpdatesQueueForCronInstance);
        $syncFileStatus = array(
            'status' => 'pending',
            'filename' => $this->_getNewSyncFileName($storeId, 'updates'),
            'products_count' => $updatesCount,
            'completed_count' => 0,
            'updated_at' => $timeNow,
            'triggered_at' => $timeNow
        );
        $this->tagalysConfiguration->setConfig("store:$storeId:updates_status", $syncFileStatus, true);
        return $syncFileStatus;
    }

    public function _updateProductsCount($storeId, $type, $collection) {
        $productsCount = $collection->getSize();
        $syncFileStatus = $this->tagalysConfiguration->getConfig("store:$storeId:{$type}_status", true);
        if ($syncFileStatus != NULL) {
            $syncFileStatus['products_count'] = $productsCount;
            $this->setSyncStatusConfig("store:$storeId:{$type}_status", $syncFileStatus, $this->pid);
        }
        return $productsCount;
    }
    public function getProductIdsForUpdate($storeId) {
        $queueCollection = $this->queueFactory->create()
            ->getCollection()
            ->addFieldToFilter('store_id', $storeId)
            ->setOrder('id', 'ASC')
            ->setPageSize($this->maxProductsForUpdate);
        $productIdsFromUpdatesQueueForCronInstance = array();
        foreach ($queueCollection as $i => $queueItem) {
            $productId = $queueItem->getProductId();
            array_push($productIdsFromUpdatesQueueForCronInstance, $productId);
        }
        return $productIdsFromUpdatesQueueForCronInstance;
    }

    public function _generateFilePart($storeId, $type, $productIdsFromUpdatesQueueForCronInstance = array()) {
        $pid = $this->pid;

        $this->tagalysApi->log('local', '1. Started _generateFilePart', array('pid' => $pid, 'storeId' => $storeId, 'type' => $type));

        $updatesPerformed = false;
        $syncFileStatus = $this->tagalysConfiguration->getConfig("store:$storeId:{$type}_status", true);
        if ($syncFileStatus == NULL) {
            if ($type == 'feed') {
                // if feed_status config is missing, generate it.
                $this->triggerFeedForStore($storeId);
            }
            if ($type == 'updates') {
                $this->_reinitializeUpdatesConfig($storeId, $productIdsFromUpdatesQueueForCronInstance);
            }
        }
        $syncFileStatus = $this->tagalysConfiguration->getConfig("store:$storeId:{$type}_status", true);

        $this->tagalysApi->log('local', '2. Read / Initialized syncFileStatus', array('pid' => $pid, 'storeId' => $storeId, 'type' => $type, 'syncFileStatus' => $syncFileStatus));

        if ($syncFileStatus != NULL) {
            if ($type == 'updates' && in_array($syncFileStatus['status'], array('finished', 'sent_to_tagalys'))) {
                // if updates are finished, reset config
                $this->_reinitializeUpdatesConfig($storeId, $productIdsFromUpdatesQueueForCronInstance);
                $syncFileStatus = $this->tagalysConfiguration->getConfig("store:$storeId:{$type}_status", true);
            }

            if (in_array($syncFileStatus['status'], array('pending', 'processing'))) {
                if ($this->_checkLock($syncFileStatus) == false) {
                    return compact('syncFileStatus');
                }

                $this->tagalysApi->log('local', '3. Unlocked', array('pid' => $pid, 'storeId' => $storeId, 'type' => $type));

                $deletedIds = array();
                if ($type == 'updates') {
                    $this->reindexProductsForUpdate($productIdsFromUpdatesQueueForCronInstance);
                    $collection = $this->_getCollection($storeId, $type, $productIdsFromUpdatesQueueForCronInstance);
                    $productIdsInCollection = array();
                    $select = $collection->getSelect();
                    $products = $select->query();
                    foreach($products as $product) {
                        array_push($productIdsInCollection, $product['entity_id']);
                    }
                    $deletedIds = array_diff($productIdsFromUpdatesQueueForCronInstance, $productIdsInCollection);
                } else {
                    $collection = $this->_getCollection($storeId, $type);
                }

                // set updated_at as this is used to check for stale processes
                $utcNow = new \DateTime("now", new \DateTimeZone('UTC'));
                $timeNow = $utcNow->format(\DateTime::ATOM);
                $syncFileStatus['updated_at'] = $timeNow;
                // update products count
                $productsCount = $this->_updateProductsCount($storeId, $type, $collection);
                if ($productsCount == 0 && count($deletedIds) == 0) {
                    if ($type == 'feed') {
                        $this->tagalysApi->log('warn', 'No products for feed generation', array('storeId' => $storeId, 'syncFileStatus' => $syncFileStatus));
                    }
                    $syncFileStatus['status'] = 'finished';
                    $this->setSyncStatusConfig("store:$storeId:{$type}_status", $syncFileStatus, $pid);
                    $updatesPerformed = true;
                    return compact('syncFileStatus', 'updatesPerformed');
                } else {
                    $syncFileStatus['locked_by'] = $pid;
                    // set status to processing
                    $syncFileStatus['status'] = 'processing';
                    $this->setSyncStatusConfig("store:$storeId:{$type}_status", $syncFileStatus, $pid);
                }

                $this->tagalysApi->log('local', '4. Locked with pid', array('pid' => $pid, 'storeId' => $storeId, 'type' => $type, 'syncFileStatus' => $syncFileStatus));

                // setup file
                $stream = $this->directory->openFile('tagalys/'.$syncFileStatus['filename'], 'a');
                $stream->lock();

                foreach($deletedIds as $i => $deletedId) {
                    $stream->write(json_encode(array("perform" => "delete", "payload" => array('__id' => $deletedId))) ."\r\n");
                }


                $cronInstanceCompletedProducts = 0;
                $cronCurrentlyCompleted = 0;

                $timeStart = time();
                $silencedException = false;
                if ($productsCount == 0) {
                    $fileGenerationCompleted = true;
                } else {
                    $fileGenerationCompleted = false;
                    $cronCurrentlyCompleted = 0;
                    try {
                        while ($cronCurrentlyCompleted < $this->maxProducts) {
                            if($this->shouldAbandonFeedAndUpdatesForStore($storeId)) {
                                $this->markFeedAsFinishedForStore($storeId);
                                return false;
                            }
                            if (isset($syncFileStatus['completed_count']) && $syncFileStatus['completed_count'] > 0) {
                                $currentPage = (int) (($syncFileStatus['completed_count'] / $this->perPage) + 1);
                            } else {
                                $currentPage = 1;
                            }
                            $triggerDatetime = strtotime($syncFileStatus['triggered_at']);
                            if ($type == 'feed') {
                                $totalProducts = $collection->clear()->getSize();
                                if ($syncFileStatus['completed_count'] >= $totalProducts){
                                    $fileGenerationCompleted = true;
                                    break;
                                } else {
                                    $collection->clear()->setPageSize($this->perPage)->setCurPage($currentPage)->load();
                                }
                            }
                            $loopCurrentlyCompleted = 0;
                            $productsToWrite = array();
                            foreach($collection as $product) {
                                $forceRegenerateThumbnail = false;
                                if ($type == 'updates') {
                                    $forceRegenerateThumbnail = true;
                                } else {
                                    if (array_key_exists('force_regenerate_thumbnails', $syncFileStatus)) {
                                        $forceRegenerateThumbnail = $syncFileStatus['force_regenerate_thumbnails'];
                                    }
                                }
                                $productDetails = (array) $this->tagalysProduct->productDetails($product, $storeId, $forceRegenerateThumbnail);

                                if (array_key_exists('scheduled_updates', $productDetails) && count($productDetails['scheduled_updates']) > 0) {
                                    for($i = 0; $i < count($productDetails['scheduled_updates']); $i++) {
                                        $atDatetime = strtotime($productDetails['scheduled_updates'][$i]['at']);
                                        unset($productDetails['scheduled_updates'][$i]['at']);
                                        $productDetails['scheduled_updates'][$i]['in'] = $atDatetime - $triggerDatetime;
                                    }
                                }

                                array_push($productsToWrite, json_encode(array("perform" => "index", "payload" => $productDetails)));
                                $loopCurrentlyCompleted += 1;
                            }
                            foreach($productsToWrite as $productToWrite) {
                                $stream->write($productToWrite."\r\n");
                            }
                            $utcNow = new \DateTime("now", new \DateTimeZone('UTC'));
                            $timeNow = $utcNow->format(\DateTime::ATOM);
                            $syncFileStatus['updated_at'] = $timeNow;
                            $syncFileStatus['completed_count'] += $loopCurrentlyCompleted;
                            $cronCurrentlyCompleted += $loopCurrentlyCompleted;
                            $this->setSyncStatusConfig("store:$storeId:{$type}_status", $syncFileStatus, $pid);
                            $timeEnd = time();

                            if ($type == 'updates') {
                                $fileGenerationCompleted = true;
                                break;
                            }
                        }
                        $this->tagalysProduct->reindexRequiredProducts();
                    } catch(LockException $e) {
                        throw $e;
                    } catch (\Exception $e) {
                        $silencedException = true;
                        $this->tagalysApi->logExceptionToTagalys($e, 'Exception in generateFilePart', ['storeId' => $storeId, 'syncFileStatus' => $syncFileStatus]);
                        try {
                            $this->tagalysProduct->reindexRequiredProducts();
                        } catch (\Exception $e) {
                            $this->tagalysApi->log('error', 'Exception in generateFilePart reindexRequiredProducts', array('storeId' => $storeId, 'syncFileStatus' => $syncFileStatus, 'message' => $e->getMessage()));
                        }
                    }
                }
                if ($silencedException == false) {
                    $updatesPerformed = true;
                }
                // close file outside of try/catch
                $stream->unlock();
                $stream->close();
                // remove lock
                $syncFileStatus['locked_by'] = null;
                $utcNow = new \DateTime("now", new \DateTimeZone('UTC'));
                $timeNow = $utcNow->format(\DateTime::ATOM);
                $syncFileStatus['updated_at'] = $timeNow;
                $timeEnd = time();
                $timeElapsed = $timeEnd - $timeStart;
                if ($fileGenerationCompleted) {
                    if($this->tagalysConfiguration->getConfig('debug:product_details', true, true)) {
                        copy($this->getSyncFilePath($syncFileStatus['filename']), $this->getSyncFilePath('debug_' . $syncFileStatus['filename']));
                    }
                    $syncFileStatus['status'] = 'generated_file';
                    $syncFileStatus['completed_count'] += count($deletedIds);
                    $this->tagalysApi->log('info', 'Completed writing ' . $syncFileStatus['completed_count'] . ' products to '. $type .' file. Last batch of ' . $cronCurrentlyCompleted . ' took ' . $timeElapsed . ' seconds.', array('storeId' => $storeId, 'syncFileStatus' => $syncFileStatus));
                } else {
                    $this->tagalysApi->log('info', 'Written ' . $syncFileStatus['completed_count'] . ' out of ' . $syncFileStatus['products_count'] . ' products to '. $type .' file. Last batch of ' . $cronCurrentlyCompleted . ' took ' . $timeElapsed . ' seconds', array('storeId' => $storeId, 'syncFileStatus' => $syncFileStatus));
                    $syncFileStatus['status'] = 'pending';
                }
                $this->setSyncStatusConfig("store:$storeId:{$type}_status", $syncFileStatus, $pid);
                $this->tagalysApi->log('local', '5. Removed lock', array('pid' => $pid, 'storeId' => $storeId, 'type' => $type, 'syncFileStatus' => $syncFileStatus));
                if ($fileGenerationCompleted) {
                    $this->_sendFileToTagalys($storeId, $type, $syncFileStatus);
                }
            } elseif (in_array($syncFileStatus['status'], array('generated_file'))) {
                $this->_sendFileToTagalys($storeId, $type, $syncFileStatus);
            }
        } else {
            $this->tagalysApi->log('error', 'Unexpected error in generateFilePart. syncFileStatus is NULL', array('storeId' => $storeId));
        }
        return compact('syncFileStatus', 'updatesPerformed');
    }

    public function getSyncFilePath($filename) {
        return $this->filesystem->getDirectoryRead('media')->getAbsolutePath('tagalys') . '/' . $filename;
    }

    public function setSyncStatusConfig($path, $value, $pid = null) {
        $currentValue = $this->tagalysConfiguration->getConfig($path, true);
        if ($currentValue) {
            if(isset($currentValue['abandon']) && $currentValue['abandon'] == true) {
                throw new LockException("abandon flag was set to true for key: {$path}");
            }
            if(!empty($currentValue['locked_by'])) {
                // currently locked my some process
                if($currentValue['locked_by'] == $pid) {
                    // Oh I know him, he is me!
                } else {
                    $secondsSinceLastUpdate = Utils::getIntervalInSeconds($currentValue['updated_at'], Utils::now());
                    if ($secondsSinceLastUpdate > (60 * 10)) {
                        // Overriding lock
                    } else {
                        throw new LockException("locked_by value is no longer valid for key: {$path}");
                    }
                }
            }
        }
        $this->tagalysConfiguration->setConfig($path, $value, true);
    }

    public function _sendFileToTagalys($storeId, $type, $syncFileStatus = null) {
        if ($syncFileStatus == null) {
            $syncFileStatus = $this->tagalysConfiguration->getConfig("store:$storeId:{$type}_status", true);
        }

        if (in_array($syncFileStatus['status'], array('generated_file'))) {
            $baseUrl = '';
            $webUrl = $this->urlInterface->getBaseUrl(array('_type' => 'web'));
            $mediaUrl = $this->urlInterface->getBaseUrl(array('_type' => 'media'));
            if (strpos($mediaUrl, $webUrl) === false) {
                // media url different from website url - probably a CDN. use website url to link to the file we create
                $baseUrl = $webUrl . 'media/';
            } else {
                $baseUrl = $mediaUrl;
            }
            $linkToFile = $baseUrl . "tagalys/" . $syncFileStatus['filename'];

            $triggerDatetime = strtotime($syncFileStatus['triggered_at']);
            $utcNow = new \DateTime("now", new \DateTimeZone('UTC'));
            $storeDomainWithPort = $this->tagalysConfiguration->getStoreDomainForId($storeId);
            $data = array(
                'link' => $linkToFile,
                'updates_count' => $syncFileStatus['products_count'],
                'store' => $storeId,
                'store_domain' => $storeDomainWithPort,
                'seconds_since_reference' => ($utcNow->getTimestamp() - $triggerDatetime),
                'callback_url' => $this->frontUrlHelper->getUrl('tagalys/sync/callback/'),
                'sync_type' => $type
            );
            $response = $this->tagalysApi->storeApiCall($storeId.'', "/v1/products/sync", $data);
            if ($response != false && $response['result']) {
                $syncFileStatus['status'] = 'sent_to_tagalys';
                $this->setSyncStatusConfig("store:$storeId:{$type}_status", $syncFileStatus);
                return true;
            } else {
                $this->tagalysApi->log('error', 'Unexpected response in _sendFileToTagalys', array('storeId' => $storeId, 'syncFileStatus' => $syncFileStatus, 'response' => $response));
            }
        } else {
            $this->tagalysApi->log('error', 'Error: Called _sendFileToTagalys with syncFileStatus ' . $syncFileStatus['status'], array('storeId' => $storeId, 'syncFileStatus' => $syncFileStatus));
        }
        return false;
    }

    public function receivedCallback($storeId, $filename) {
        $type = null;
        if (strpos($filename, '-feed-') !== false) {
            $type = 'feed';
        } elseif (strpos($filename, '-updates-') !== false) {
            $type = 'updates';
        }
        if ($type == 'updates') {
            $response = $this->deleteSyncFiles([$filename]);
            if($response['errors']) {
                $this->tagalysApi->log('warn', 'Unable to delete file in receivedCallback', array('filename' => $filename));
            }
            $this->tagalysApi->log('info', 'Updates sync completed.', array('store_id' => $storeId));
            return true;
        } else {
            $syncFileStatus = $this->tagalysConfiguration->getConfig("store:$storeId:{$type}_status", true);
            if ($syncFileStatus && $syncFileStatus['status'] == 'sent_to_tagalys') {
                if ($syncFileStatus['filename'] == $filename) {
                    $filePath = $this->filesystem->getDirectoryRead('media')->getAbsolutePath() . 'tagalys/' . $filename;
                    if (!file_exists($filePath) || !unlink($filePath)) {
                        $this->tagalysApi->log('warn', 'Unable to delete file in receivedCallback', array('syncFileStatus' => $syncFileStatus, 'filename' => $filename));
                    }
                    $syncFileStatus['status'] = 'finished';
                    $utcNow = new \DateTime("now", new \DateTimeZone('UTC'));
                    $timeNow = $utcNow->format(\DateTime::ATOM);
                    $syncFileStatus['updated_at'] = $timeNow;
                    $this->setSyncStatusConfig("store:$storeId:{$type}_status", $syncFileStatus);
                    if ($type == 'feed') {
                        $this->tagalysConfiguration->setConfig("store:$storeId:setup_complete", '1');
                        $this->tagalysApi->log('info', 'Feed sync completed.', array('store_id' => $storeId));
                        $this->tagalysConfiguration->checkStatusCompleted();
                    } else {
                        $this->tagalysApi->log('info', 'Updates sync completed.', array('store_id' => $storeId));
                    }
                } else {
                    $this->tagalysApi->log('warn', 'Unexpected filename in receivedCallback', array('syncFileStatus' => $syncFileStatus, 'filename' => $filename));
                }
            } else {
                $this->tagalysApi->log('warn', 'Unexpected receivedCallback trigger', array('syncFileStatus' => $syncFileStatus, 'filename' => $filename));
            }
        }
    }

    public function status() {
        $storesSyncRequired = false;
        $waitingForTagalys = false;
        $resyncScheduled = false;
        $syncStatus = array();
        $setupStatus = $this->tagalysConfiguration->getConfig('setup_status');
        $setupComplete = ($setupStatus == 'completed');
        $syncStatus['setup_complete'] = $setupComplete;
        $syncStatus['stores'] = array();
        foreach ($this->tagalysConfiguration->getStoresForTagalys() as $key => $storeId) {
            $thisStore = array();

            $thisStore['name'] = $this->storeManager->getStore($storeId)->getName();

            $storeSetupComplete = $this->tagalysConfiguration->getConfig("store:$storeId:setup_complete");
            $thisStore['setup_complete'] = ($storeSetupComplete == '1');

            $storeFeedStatus = $this->tagalysConfiguration->getConfig("store:$storeId:feed_status", true);
            if ($storeFeedStatus != null) {
                $statusForClient = '';
                switch($storeFeedStatus['status']) {
                    case 'pending':
                        $statusForClient = 'Waiting to write to file';
                        $storesSyncRequired = true;
                        break;
                    case 'processing':
                        $statusForClient = 'Writing to file';
                        $storesSyncRequired = true;
                        break;
                    case 'generated_file':
                        $statusForClient = 'Generated file. Sending to Tagalys.';
                        $storesSyncRequired = true;
                        break;
                    case 'sent_to_tagalys':
                        $statusForClient = 'Waiting for Tagalys';
                        $waitingForTagalys = true;
                        break;
                    case 'finished':
                        $statusForClient = 'Finished';
                        break;
                }
                $storeResyncRequired = $this->tagalysConfiguration->getConfig("store:$storeId:resync_required");
                if ($storeResyncRequired == '1') {
                    $resyncScheduled = true;
                    if ($statusForClient == 'Finished') {
                        $statusForClient = 'Scheduled as per Cron settings';
                    }
                }
                if ($statusForClient == 'Writing to file' || $statusForClient == 'Waiting to write to file') {
                    if ((int)$storeFeedStatus['products_count'] == 0){
                        $statusForClient = $statusForClient . ' (completed 100%)';
                    } else {
                        $completed_percentage = round(((int)$storeFeedStatus['completed_count'] / (int)$storeFeedStatus['products_count']) * 100, 2);
                        $statusForClient = $statusForClient . ' (completed '.$completed_percentage.'%)';
                    }
                }
                $thisStore['feed_status'] = $statusForClient;
            } else {
                $storesSyncRequired = true;
            }

            $storeUpdatesStatus = $this->tagalysConfiguration->getConfig("store:$storeId:updates_status", true);
            $remainingUpdates = $this->queueFactory->create()->getCollection()->addFieldToFilter('store_id', $storeId)->getSize();
            if ($thisStore['setup_complete']) {
                if ($remainingUpdates > 0) {
                    $storesSyncRequired = true;
                    $thisStore['updates_status'] = $remainingUpdates . ' remaining';
                } else {
                    if ($storeUpdatesStatus == null) {
                        $thisStore['updates_status'] = 'Nothing to update';
                    } else {
                        switch($storeUpdatesStatus['status']) {
                            case 'processing':
                                // Updates are running but the tagalys_queue table is cleared through API
                                $thisStore['updates_status'] = 'Processing updates';
                                $storesSyncRequired = true;
                                break;
                            case 'generated_file':
                                $thisStore['updates_status'] = 'Generated file. Sending to Tagalys.';
                                $storesSyncRequired = true;
                                break;
                            case 'sent_to_tagalys':
                            case 'finished':
                                $thisStore['updates_status'] = 'Finished';
                                break;
                        }
                    }
                }
            } else {
                if ($remainingUpdates > 0) {
                    $thisStore['updates_status'] = 'Waiting for feed sync';
                } else {
                    $thisStore['updates_status'] = 'Nothing to update';
                }
            }

            // categories
            $listingPagesEnabled = $this->tagalysConfiguration->getConfig("module:listingpages:enabled");
            $totalEnabled = $this->tagalysCategory->getEnabledCount($storeId);
            if ($listingPagesEnabled != '0' && $totalEnabled > 0) {
                $pendingSync = $this->tagalysCategory->getPendingSyncCount($storeId);
                $requiringPositionsSync = $this->tagalysCategory->getRequiringPositionsSyncCount($storeId);
                $listingPagesStatusMessages = array();
                if ($pendingSync > 0) {
                    array_push($listingPagesStatusMessages, 'Pending sync to Tagalys: '.$pendingSync);
                }
                if ($requiringPositionsSync > 0) {
                    array_push($listingPagesStatusMessages, 'Positions update required: ' . $requiringPositionsSync);
                }
                if (empty($listingPagesStatusMessages)) {
                    array_push($listingPagesStatusMessages, 'Finished');
                }
                $thisStore['listing_pages_status'] = implode(". ", $listingPagesStatusMessages);
            } else {
                $thisStore['listing_pages_status'] = 'Not enabled';
            }

            $syncStatus['stores'][$storeId] = $thisStore;
        }
        $syncStatus['client_side_work_completed'] = false;
        $configSyncRequired = $this->tagalysConfiguration->getConfig('config_sync_required');
        if ($storesSyncRequired == true || $configSyncRequired == '1') {
            if ($storesSyncRequired == true) {
                $syncStatus['status'] = 'Stores Sync Pending';
            } else {
                if ($configSyncRequired == '1') {
                    $syncStatus['status'] = 'Configuration Sync Pending';
                } else {
                    // should never come here
                    $syncStatus['status'] = 'Pending';
                }
            }
        } else {
            $syncStatus['client_side_work_completed'] = true;
            if ($waitingForTagalys) {
                $syncStatus['waiting_for_tagalys'] = true;
                $syncStatus['status'] = 'Waiting for Tagalys';
            } else {
                $syncStatus['status'] = 'Fully synced';
            }
        }

        if ($resyncScheduled) {
            $syncStatus['status'] = $syncStatus['status'] . '. Resync scheduled as per Cron settings. You can trigger it manually by using the <strong>Trigger full products resync now</strong> option in the <strong>Support & Troubleshooting</strong> tab.';
        }

        return $syncStatus;
    }

    public function updateIntegration($permissions) {
        $this->tagalysConfiguration->setConfig('integration_permissions', $permissions);
        $integration = $this->integrationFactory->create()->load('Tagalys', 'name');
        $permissions = $this->tagalysConfiguration->getConfig('integration_permissions');
        $this->authorizationService->grantPermissions($integration->getId(), $permissions);
        return $permissions;
    }

    public function getOrderData($storeId, $from, $to=false){
        $from = date('Y-m-d H:i:s', $from);
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $order = $objectManager->get('\Magento\Sales\Model\Order');
        $orders = $order->getCollection()->addFieldToFilter('store_id',$storeId)->addAttributeToFilter('created_at', ['from' => $from]);
        if($to){
            $to = date('Y-m-d H:i:s', $to);
            $orders->addAttributeToFilter('created_at', ['to' => $to]);
        }
        $data = [];
        $successStates = $this->tagalysConfiguration->getConfig('success_order_states', true);
        $considerOrderIncrementIdAsOrderId = $this->tagalysConfiguration->getConfig('consider_order_increment_id_as_order_id', true, true);
        foreach($orders as $order){
            if (in_array($order->getState(), $successStates)) {
                $items = $order->getAllVisibleItems();
                foreach($items as $item){
                    $product = $item->getProduct();
                    if(is_null($product)){
                        continue;
                    }
                    if($considerOrderIncrementIdAsOrderId){
                        $orderId = $order->getIncrementId();
                    } else {
                        $orderId = $order->getId();
                    }
                    $data[] = [
                        'order_id' => $orderId,
                        'order_status' => $order->getStatus(),
                        'order_state' => $order->getState(),
                        'item_sku' => $item->getSku(),
                        'product_sku' => $product->getSku(),
                        'qty' => $item->getQtyOrdered(),
                        'user_id' => $order->getCustomerId(),
                        'timestamp' => $order->getCreatedAt(),
                    ];
                }
            }
        }
        return $data;
    }

    public function reindexProductsForUpdate($productIds){
        /*
            In certain cases (flat_products enabled maybe?), when a new product is created the product update for that product ID will come as 'product delete'
            If that happens, enable sync:reindex_products_before_updates
        */
        $reindexBeforeUpdate = $this->tagalysConfiguration->getConfig('sync:reindex_products_before_updates', true);
        if($reindexBeforeUpdate && count($productIds) > 0){
            $this->indexerFactory->create()->load('cataloginventory_stock')->reindexList($productIds);
            $this->indexerFactory->create()->load('catalog_product_price')->reindexList($productIds);
        }
    }

    public function deleteSyncFiles($filesToDelete) {
        $response = ['failed_to_delete' => [], 'errors' => false];
        $this->forEachSyncFile(function($fullPath, $fileName) use ($filesToDelete, &$response) {
            if (in_array($fileName, $filesToDelete)) {
                $deleted = false;
                try {
                    $deleted = unlink($fullPath);
                } catch (\Exception $e) {}
                if(!$deleted) {
                    $response['errors'] = true;
                    $response['failed_to_delete'][] = $fileName;
                }
            }
        });
        return $response;
    }

    public function deleteAllSyncFiles() {
        $this->forEachSyncFile(function($fullPath, $_){
            try {
                unlink($fullPath);
            } catch (\Exception $e) { }
        });
        return true;
    }

    public function forEachFileInMediaFolder($callback) {
        $mediaDirectory = $this->filesystem->getDirectoryRead('media')->getAbsolutePath('tagalys');
        $filesInMediaDirectory = scandir($mediaDirectory);
        foreach ($filesInMediaDirectory as $key => $value) {
            if (!is_dir($mediaDirectory . DIRECTORY_SEPARATOR . $value)) {
                if (!preg_match("/^\./", $value)) {
                    $name = $value;
                    $path = $mediaDirectory . DIRECTORY_SEPARATOR . $name;
                    $callback($path, $name);
                }
            }
        }
    }

    public function forEachSyncFile($callback) {
        $this->forEachFileInMediaFolder(function($path, $name) use ($callback) {
            if (substr($name, 0, 8) == 'syncfile'){
                $callback($path, $name);
            }
        });
    }

    public function triggerFullSync(){
        $this->tagalysConfiguration->setConfig("config_sync_required", '1');
        foreach ($this->tagalysConfiguration->getStoresForTagalys() as $storeId) {
            $this->triggerFeedForStore($storeId, true, false, true);
        }
    }

    public function triggerQuickFeed($storeIds = false){
        if($storeIds === false) {
            $storeIds = $this->tagalysConfiguration->getStoresForTagalys();
        }
        foreach ($storeIds as $storeId) {
            $statusPath = "store:$storeId:quick_feed_status";
            $this->tagalysConfiguration->setConfig($statusPath, ['status' => 'scheduled'], true);
        }
    }

    public function lockedCronOperation($callback) {
        $configPath = 'cron_status';
        $status = $this->tagalysConfiguration->getConfig($configPath, true);
        $now = new \DateTime();
        if($status && isset($status['locked_by'])) {
            $lockedAt = new \DateTime($status['updated_at']);
            $intervalSeconds = $now->getTimestamp() - $lockedAt->getTimestamp();
            $minSecondsForOverride = 10 * 60;
            if ($intervalSeconds < $minSecondsForOverride) {
                // updated less than 10 min ago
                return false;
            } else {
                // log: override lock
            }
        }

        $this->raiseIfForceRevoked();

        $pid = $this->random->getRandomString(24);
        $this->tagalysConfiguration->updateJsonConfig($configPath, [
            'locked_by' => $pid,
            'updated_at' => $this->now(),
            'status' => 'processing'
        ]);

        $callback();

        $this->tagalysConfiguration->updateJsonConfig($configPath, [
            'locked_by' => null,
            'updated_at' => $this->now(),
            'status' => 'finished'
        ]);

        return true;
    }
    public function touchLock() {
        $this->raiseIfForceRevoked();
        $this->tagalysConfiguration->updateJsonConfig('cron_status', [
            'updated_at' => $this->now(),
        ]);
    }
    public function now() {
        $utcNow = new \DateTime("now", new \DateTimeZone('UTC'));
        return $utcNow->format(\DateTime::ATOM);
    }
    public function sleepIfRequired($productCount) {
        $cronConfig = $this->tagalysConfiguration->getCronConfig();
        if(array_key_exists('sleep', $cronConfig)) {
            if(($productCount > 0)  && ($productCount % $cronConfig['sleep_every'] == 0)) {
                // don't sleep for more than 5 min
                sleep(min($cronConfig['sleep'], 300));
            }
        }
    }
    public function raiseIfForceRevoked() {
        $forceRevoked = $this->tagalysConfiguration->getConfig('force_revoke_cron_lock', true);
        if($forceRevoked) {
            throw new \Exception("Tagalys cron has been stopped forcefully. Remove force_revoke_cron_lock key from tagalys_config to resume.");
        }
    }

    public function runQuickFeedIfRequired($stores) {
        foreach($stores as $storeId) {
            $quickFeedStatus = $this->tagalysConfiguration->getQuickFeedStatus($storeId);
            if($quickFeedStatus['status'] == 'scheduled') {
                $fileName = $this->_getNewSyncFileName($storeId, self::QUICK_FEED);
                $statusPath = "store:$storeId:quick_feed_status";
                $this->tagalysConfiguration->setConfig($statusPath, [
                    'status' => 'processing',
                    'filename' => $fileName,
                    'triggered_at' => $this->now(),
                    'products_count' => $this->getProductsCount($storeId),
                    'completed_count' => 0
                ], true);
                $collection = $this->_getCollection($storeId);
                $this->syncToFile($storeId, $fileName, $collection, function($storeId, $product) {
                    try {
                        return $this->tagalysProduct->getSelectiveProductDetails($storeId, $product);
                    } catch (\Throwable $e) {
                        $this->tagalysApi->log('local', 'error in runQuickFeedIfRequired', Utils::getExceptionDetails($e));
                        return [];
                    }
                }, $statusPath);

                $quickFeedStatus = $this->tagalysConfiguration->getQuickFeedStatus($storeId);
                $quickFeedStatus['status'] = 'generated_file';
                $quickFeedStatus['took'] = strtotime($this->now()) - strtotime($quickFeedStatus['triggered_at']);
                $this->tagalysConfiguration->setConfig($statusPath, $quickFeedStatus, true);
                $this->_sendFileToTagalys($storeId, self::QUICK_FEED, $quickFeedStatus);
            } else if ($quickFeedStatus['status'] == 'generated_file') {
                // already generated file, sending failed maybe
                $this->_sendFileToTagalys($storeId, self::QUICK_FEED, $quickFeedStatus);
            }
        }
    }

    public function syncToFile($storeId, $fileName, $collection, $getProductDetails, $statusPath) {
        $rowsToWrite = [];
        $completedCount = 0;
        foreach($collection as $product) {
            $productDetails = $getProductDetails($storeId, $product);
            $rowsToWrite[] = json_encode($productDetails);

            $completedCount++;
            if($completedCount % 50 == 0) {
                $this->touchLock();
                $this->writeToFile($fileName, $rowsToWrite);
                $this->updateCompletedCount($statusPath, $completedCount);
                $completedCount = 0;
                $rowsToWrite = [];
            }
            $this->sleepIfRequired($completedCount);
        }
        if(count($rowsToWrite) > 0) {
            $this->writeToFile($fileName, $rowsToWrite);
            $this->updateCompletedCount($statusPath, $completedCount);
        }
        $this->tagalysProduct->reindexRequiredProducts();
    }

    public function updateCompletedCount($statusPath, $completedCount) {
        $status = $this->tagalysConfiguration->getConfig($statusPath, true);
        $status['completed_count'] += $completedCount;
        $this->tagalysConfiguration->setConfig($statusPath, $status, true);
    }

    public function writeToFile($fileName, $rows) {
        $stream = $this->directory->openFile("tagalys/$fileName", 'a');
        $stream->lock();
        foreach($rows as $row) {
            $stream->write("$row\r\n");
        }
        $stream->unlock();
        $stream->close();
    }

    public function deleteFromPriorityUpdateQueue($productIds) {
        Utils::forEachChunk($productIds, 500, function($productIdsForThisBatch) {
            $this->queueFactory->create()
                ->getCollection()
                ->addFieldToFilter('product_id', $productIdsForThisBatch)
                ->walk('delete');
        });
    }

    public function getProductIdsForPriorityUpdate() {
        $collection = $this->queueFactory->create()->getCollection()->addFieldToFilter('priority', ['gt' => 0])->setOrder('priority', 'desc');
        $productIds = array_map(function($item){
            return $item['product_id'];
        }, $collection->toArray(['product_id'])['items']);
        return $productIds;
    }

    public function areAllUpdatesSentToTagalys($stores) {
        foreach($stores as $storeId) {
            $updateStatus = $this->tagalysConfiguration->getPriorityUpdatesStatus($storeId);
            if($updateStatus['status'] == 'generated_file') {
                return false;
            }
        }
        return true;
    }

    public function runPriorityUpdatesIfRequired($stores) {
        $areAllUpdatesSentToTagalys = $this->areAllUpdatesSentToTagalys($stores);
        if(!$areAllUpdatesSentToTagalys) {
            $areAllUpdatesSentToTagalys = true;
            foreach($stores as $storeId) {
                $updateStatus = $this->tagalysConfiguration->getPriorityUpdatesStatus($storeId);
                if($updateStatus['status'] == 'generated_file') {
                    if(!$this->_sendFileToTagalys($storeId, self::PRIORITY_UPDATES, $updateStatus)) {
                        $areAllUpdatesSentToTagalys = false;
                    }
                }
            }
        }
        if(!$areAllUpdatesSentToTagalys) {
            return false;
        }

        $productIds = $this->getProductIdsForPriorityUpdate();
        $updatesCount = count($productIds);
        if($updatesCount > 0) {
            foreach($stores as $storeId) {
                $fileName = $this->_getNewSyncFileName($storeId, self::PRIORITY_UPDATES);
                $statusPath = "store:$storeId:priority_updates_status";
                $this->tagalysConfiguration->setConfig($statusPath, [
                    'status' => 'processing',
                    'filename' => $fileName,
                    'triggered_at' => $this->now(),
                    'products_count' => $updatesCount,
                    'completed_count' => 0
                ], true);
                $processedProductIds = [];
                Utils::forEachChunk($productIds, 500, function($productIdsForThisBatch) use ($storeId, $fileName, &$processedProductIds, $statusPath) {
                    $collection = $this->_getCollection($storeId, 'updates', $productIdsForThisBatch);
                    $this->syncToFile($storeId, $fileName, $collection, function($storeId, $product) use (&$processedProductIds) {
                        $processedProductIds[] = $product->getId();
                        try {
                            $productDetails = (array) $this->tagalysProduct->productDetails($product, $storeId, true);
                        } catch (\Throwable $e) {
                            $this->tagalysApi->log('local', 'error in runPriorityUpdatesIfRequired', Utils::getExceptionDetails($e));
                            $productDetails = [];
                        }
                        return ["perform" => "index", "payload" => $productDetails];
                    }, $statusPath);
                });
                $deletedIds = array_diff($productIds, $processedProductIds);
                $deleteRows = array_map(function($deletedId){
                    return json_encode(["perform" => "delete", "payload" => ['__id' => $deletedId]]);
                }, $deletedIds);
                $this->writeToFile($fileName, $deleteRows);
                $this->updateCompletedCount($statusPath, count($deletedIds));

                $updateStatus = $this->tagalysConfiguration->getConfig($statusPath, true);
                $updateStatus['status'] = 'generated_file';
                $this->tagalysConfiguration->setConfig($statusPath, $updateStatus, true);
                $this->_sendFileToTagalys($storeId, self::PRIORITY_UPDATES, $updateStatus);
            }
            $this->deleteFromPriorityUpdateQueue($productIds);
        }
    }

    public function truncateQueueAndTriggerSyncIfRequired($storeId) {
        $maxAllowedUpdatesCount = (int) $this->tagalysConfiguration->getConfig("sync:threshold_to_abandon_updates_and_trigger_feed");
        $cutOffThresholdPercentage = (float) $this->tagalysConfiguration->getConfig("sync:threshold_to_abandon_updates_and_trigger_feed_percentage");
        $resyncTriggered = false;
        $entries = $this->queueFactory->create()
            ->getCollection()
            ->addFieldToFilter('priority', 0)
            ->addFieldToFilter('store_id', $storeId)
            ->toArray();
        $productIdsInQueue = array_map(function($item){
            return $item['product_id'];
        }, $entries['items']);
        if ($this->tagalysConfiguration->getConfig("fallback:use_all_products_count_for_truncating_queue", true, true)) {
            $productIdsForUpdate = $productIdsInQueue;
        } else {
            $productIdsForUpdate = $this->filterProductIdsForUpdate($storeId, $productIdsInQueue);
        }
        $updatesCount = count($productIdsForUpdate);
        if($updatesCount > $maxAllowedUpdatesCount) {
            $totalProducts = $this->getProductsCount($storeId);
            $cutoff = $cutOffThresholdPercentage * $totalProducts;
            if ($updatesCount > $cutoff) {
                $resyncTriggered = true;
                $this->queueHelper->deleteByPriority(0, $storeId);
                $this->triggerFeedForStore($storeId, false, false, true);
                $this->tagalysApi->log('warn', 'Clearing updates queue and triggering full products sync', array('remainingProductUpdates' => $updatesCount));
            }
        }
        return $resyncTriggered;
    }

    public function filterProductIdsForUpdate($storeId, $productIds) {
        $productIdsForUpdate = [];
        $results = $this->_getCollection($storeId, 'updates', $productIds)->toArray();
        foreach ($results as $key => $value) {
            $productIdsForUpdate[] = $value['entity_id'];
        }
        return $productIdsForUpdate;
    }

    public function shouldAbandonFeedAndUpdatesForStore($storeId) {
        return !!$this->tagalysConfiguration->getConfig("store:$storeId:abandon_feed_and_updates", true);
    }

    public function markFeedAsFinishedForStore($storeId) {
        $feedStatus = $this->tagalysConfiguration->getConfig("store:$storeId:feed_status", true);
        if($feedStatus && ($feedStatus['status'] !== 'finished')) {
            $this->tagalysConfiguration->updateJsonConfig("store:$storeId:feed_status", [
                'status' => 'finished'
            ]);
            $this->tagalysApi->log('warn', "Feed has been abandoned and marked as finished for store: $storeId");
        }
    }

    public function getProductIdsToRemove($storeId, $productIds) {
        $idsToRemove = [];
        Utils::forEachChunk($productIds, 1000, function($idsChunk) use ($storeId, &$idsToRemove) {
            $collection = $this->_getCollection($storeId, 'updates', $idsChunk);
            $idsToKeep = [];
            foreach($collection as $product) {
                $idsToKeep[] = $product->getId();
            }
            $idsToRemoveInThisBatch = array_diff($idsChunk, $idsToKeep);
            $idsToRemove = array_merge($idsToRemove, $idsToRemoveInThisBatch);
        });
        return $idsToRemove;
    }
}
