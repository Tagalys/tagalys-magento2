<?php
namespace Tagalys\Sync\Helper;

class Product extends \Magento\Framework\App\Helper\AbstractHelper
{
    private $productsToReindex = array();

    private $productFactory;
    private $linkManagement;
    private $grouped;
    private $datetime;
    private $timezoneInterface;
    private $directoryList;
    protected $scopeConfig;
    private $productMediaConfig;
    private $storeManager;
    private $imageFactory;
    private $reviewCollectionFactory;
    private $ratingCollectionFactory;
    private $filesystem;
    private $categoryRepository;
    private $tagalysConfiguration;
    private $tagalysCategory;
    private $swatchesHelper;
    private $swatchesMediaHelper;
    private $productAttributeRepository;
    private $eventManager;
    private $indexerRegistry;
    private $stockRegistry;
    private $priceCurrency;
    private $productMetadata;
    private $configurableProduct;
    private $resourceConnection;
    private $auditLog;
    private $logger;


    public function __construct(
        \Magento\Catalog\Model\ProductFactory $productFactory,
        \Magento\ConfigurableProduct\Api\LinkManagementInterface $linkManagement,
        \Magento\GroupedProduct\Model\Product\Type\Grouped $grouped,
        \Magento\Framework\Stdlib\DateTime\DateTime $datetime,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $timezoneInterface,
        \Magento\Framework\App\Filesystem\DirectoryList $directoryList,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Catalog\Model\Product\Media\Config $productMediaConfig,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\Image\AdapterFactory $imageFactory,
        \Magento\Review\Model\ResourceModel\Review\CollectionFactory $reviewCollectionFactory,
        \Magento\Review\Model\ResourceModel\Rating\CollectionFactory $ratingCollectionFactory,
        \Magento\Framework\Filesystem $filesystem,
        \Magento\Catalog\Api\CategoryRepositoryInterface $categoryRepository,
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration,
        \Tagalys\Sync\Helper\Category $tagalysCategory,
        \Magento\Swatches\Helper\Data $swatchesHelper,
        \Magento\Swatches\Helper\Media $swatchesMediaHelper,
        \Magento\Catalog\Model\Product\Attribute\Repository $productAttributeRepository,
        \Magento\Framework\Event\Manager $eventManager,
        \Magento\Framework\Indexer\IndexerRegistry $indexerRegistry,
        \Magento\CatalogInventory\Api\StockRegistryInterface $stockRegistry,
        \Magento\Framework\Pricing\PriceCurrencyInterface $priceCurrency,
        \Magento\Framework\App\ProductMetadataInterface $productMetadata,
        \Magento\ConfigurableProduct\Model\Product\Type\Configurable $configurableProduct,
        \Magento\Framework\App\ResourceConnection $resourceConnection,
        \Tagalys\Sync\Helper\AuditLog $auditLog
    )
    {
        $this->productFactory = $productFactory;
        $this->linkManagement = $linkManagement;
        $this->grouped = $grouped;
        $this->datetime = $datetime;
        $this->timezoneInterface = $timezoneInterface;
        $this->directoryList = $directoryList;
        $this->scopeConfig = $scopeConfig;
        $this->productMediaConfig = $productMediaConfig;
        $this->storeManager = $storeManager;
        $this->imageFactory = $imageFactory;
        $this->reviewCollectionFactory = $reviewCollectionFactory;
        $this->ratingCollectionFactory = $ratingCollectionFactory;
        $this->filesystem = $filesystem;
        $this->categoryRepository = $categoryRepository;
        $this->tagalysConfiguration = $tagalysConfiguration;
        $this->tagalysCategory = $tagalysCategory;
        $this->swatchesHelper = $swatchesHelper;
        $this->swatchesMediaHelper = $swatchesMediaHelper;
        $this->productAttributeRepository = $productAttributeRepository;
        $this->eventManager = $eventManager;
        $this->indexerRegistry = $indexerRegistry;
        $this->stockRegistry = $stockRegistry;
        $this->priceCurrency = $priceCurrency;
        $this->productMetadata = $productMetadata;
        $this->configurableProduct = $configurableProduct;
        $this->resourceConnection = $resourceConnection;
        $this->auditLog = $auditLog;

        $logLevel = $this->tagalysConfiguration->getLogLevel();
        $this->logger = Utils::getLogger("tagalys_product_helper.log", $logLevel);
    }

    public function getPlaceholderImageUrl($imageAttributeCode, $allowPlaceholder) {
        try {
            if ($allowPlaceholder) {
                return $this->storeManager->getStore()->getBaseUrl('media') . $this->productMediaConfig->getBaseMediaUrlAddition() . DIRECTORY_SEPARATOR . 'placeholder' . DIRECTORY_SEPARATOR . $this->scopeConfig->getValue("catalog/placeholder/{$imageAttributeCode}_placeholder");
            } else {
                return null;
            }
        } catch(\Exception $e) {
            return null;
        }
    }

    public function getProductImageUrl($storeId, $imageAttributeCode, $allowPlaceholder, $product, $forceRegenerateThumbnail) {
        $debug = ($this->tagalysConfiguration->getConfig("debug:product_image_sync", true, true) == true);
        $productId = $product->getEntityId();
        try {
            $productImagePath = $product->getData($imageAttributeCode);
            if ($productImagePath != null) {
                $baseProductImagePath = $this->filesystem->getDirectoryRead('media')->getAbsolutePath($this->productMediaConfig->getBaseMediaUrlAddition()) . $productImagePath;
                // $baseProductImagePath = $this->directoryList->getPath('media') . DIRECTORY_SEPARATOR . "catalog" . DIRECTORY_SEPARATOR . "product" . $productImagePath;
                if(file_exists($baseProductImagePath)) {
                    $imageDetails = getimagesize($baseProductImagePath);
                    $width = $imageDetails[0];
                    $height = $imageDetails[1];
                    if ($width > 1 && $height > 1) {
                        $resizedProductImagePath = $this->filesystem->getDirectoryRead('media')->getAbsolutePath('tagalys/product_images') . $productImagePath;
                        // $resizedProductImagePath = $this->directoryList->getPath('media') . DIRECTORY_SEPARATOR . 'tagalys' . DIRECTORY_SEPARATOR . 'product_thumbnails' . $productImagePath;
                        if ($forceRegenerateThumbnail || !file_exists($resizedProductImagePath)) {
                            if (file_exists($resizedProductImagePath)) {
                                try{
                                    unlink($resizedProductImagePath);
                                } catch(\Exception $e){
                                }
                            }
                            $imageResize = $this->imageFactory->create();
                            $imageResize->open($baseProductImagePath);
                            $imageResize->constrainOnly(TRUE);
                            $imageResize->keepTransparency(TRUE);
                            $imageResize->keepFrame(FALSE);
                            $imageResize->keepAspectRatio(TRUE);
                            $imageResize->quality($this->tagalysConfiguration->getConfig('product_thumbnail_quality'));
                            $imageResize->resize($this->tagalysConfiguration->getConfig('max_product_thumbnail_width'), $this->tagalysConfiguration->getConfig('max_product_thumbnail_height'));
                            $imageResize->save($resizedProductImagePath);
                        }
                        if (file_exists($resizedProductImagePath)) {
                            return str_replace('http:', '', $this->storeManager->getStore()->getBaseUrl('media') . 'tagalys/product_images' . $productImagePath);
                        } else {
                            if($debug) {
                                $this->logger->warn("Resized image file ($resizedProductImagePath) not found for product $productId. Returning placeholder image.");
                            }
                            return $this->getPlaceholderImageUrl($imageAttributeCode, $allowPlaceholder);
                        }
                    } else {
                        if ($debug) {
                            $this->logger->warn("Image dimensions are not > 1 (width: $width, height: $height) for product $productId. Returning placeholder image.");
                        }
                        return $this->getPlaceholderImageUrl($imageAttributeCode, $allowPlaceholder);
                    }
                } else {
                    if($debug) {
                        $this->logger->warn("Base image file ($baseProductImagePath) not found for product $productId. Returning placeholder image.");
                    }
                    return $this->getPlaceholderImageUrl($imageAttributeCode, $allowPlaceholder);
                }
            } else {
                if($debug) {
                    $this->logger->warn("Product image path is blank ($productImagePath) for product $productId. Returning placeholder image.");
                }
                return $this->getPlaceholderImageUrl($imageAttributeCode, $allowPlaceholder);
            }
        } catch(\Exception $e) {
            if($debug) {
                $this->logger->err(json_encode([
                    'message' => "Exception in getProductImageUrl for product $productId. Returning placeholder image.",
                    'exception' => Utils::getExceptionDetails($e)
                ]));
            }
            return $this->getPlaceholderImageUrl($imageAttributeCode, $allowPlaceholder);
        }
    }

    public function getProductFields($product) {
        $productFields = array();
        $attributes = $product->getTypeInstance()->getEditableAttributes($product);
        $attributesToIgnore = array();
        if ($product->getTypeId() === "configurable") {
            $attributesToIgnore = array_map(function ($el) {
                return $el['attribute_code'];
            }, $product->getTypeInstance(true)->getConfigurableAttributesAsArray($product));
        }
        $storeId = $this->storeManager->getStore()->getId();
        $whitelistedAttributes = $this->tagalysConfiguration->getConfig('sync:whitelisted_product_attributes', true);
        foreach ($attributes as $attribute) {
            $attributeCode = $attribute->getAttributeCode();
            if($this->tagalysConfiguration->isAttributeField($attribute)) {
                $shouldSyncAttribute = $this->tagalysConfiguration->shouldSyncAttribute($attribute, $whitelistedAttributes, $attributesToIgnore);
                if($shouldSyncAttribute) {
                    $isBoolean = $attribute->getFrontendInput() == 'boolean';
                    if($isBoolean) {
                        $productFields[$attributeCode] = $this->getBooleanAttributeValue($storeId, $product, $attribute);
                    } else {
                        $attributeValue = $attribute->getFrontend()->getValue($product);
                        if (!is_null($attributeValue)) {
                            $productFields[$attributeCode] = $attributeValue;
                        }
                    }
                }
            }
        }
        return $productFields;
    }

    public function getBooleanAttributeValue($storeId, $product, $attribute) {
        $readBooleanValuesViaDb = $this->tagalysConfiguration->getConfig('sync:read_boolean_attributes_via_db', true);
        if($readBooleanValuesViaDb) {
            return $this->getBooleanAttributeValueViaDb($storeId, $product->getId(), $attribute->getAttributeId());
        }
        $newMethod = $this->tagalysConfiguration->getConfig('temp:read_boolean_attributes_with_new_method', true);
        if($newMethod) {
            $attributeValue = $product->getAttributeText($attribute->getAttributeCode());
        } else {
            $attributeValue = $attribute->getFrontend()->getValue($product);
        }
        return ($attributeValue == 'Yes');
    }

    public function getBooleanAttributeValueViaDb($storeId, $productId, $attributeId) {
        $cpe = $this->resourceConnection->getTableName('catalog_product_entity');
        $cpei = $this->resourceConnection->getTableName('catalog_product_entity_int');
        $columnToJoin = $this->tagalysConfiguration->getResourceColumnToJoin();
        $sql = "SELECT * FROM $cpei AS cpei INNER JOIN $cpe AS cpe ON cpe.{$columnToJoin} = cpei.{$columnToJoin} WHERE cpe.entity_id = $productId AND cpei.attribute_id = $attributeId AND cpei.store_id IN (0, $storeId) ORDER BY cpei.store_id DESC";
        $rows = $this->runSqlSelect($sql);
        return (count($rows) > 0 && $rows[0]['value'] == '1');
    }

    public function getDirectProductTags($product, $storeId) {
        $productTags = array();

        // categories
        array_push($productTags, array("tag_set" => array("id" => "__categories", "label" => "Categories" ), "items" => $this->getProductCategories($product, $storeId)));

        // other attributes
        $attributes = $product->getTypeInstance()->getEditableAttributes($product);
        $whitelistedAttributes = $this->tagalysConfiguration->getConfig('sync:whitelisted_product_attributes', true);
        foreach ($attributes as $attribute) {
            if($this->tagalysConfiguration->isAttributeTagSet($attribute)) {
                $shouldSyncAttribute = $this->tagalysConfiguration->shouldSyncAttribute($attribute, $whitelistedAttributes);
                if ($shouldSyncAttribute) {
                    $productAttribute = $product->getResource()->getAttribute($attribute->getAttributeCode());
                    // select, multi-select
                    $fieldType = $productAttribute->getFrontendInput();
                    $items = array();
                    if ($fieldType == 'multiselect') {
                        $value = $product->getData($attribute->getAttributeCode());
                        $ids = [];
                        if (isset($value)) {
                            $ids = explode(',', $value);
                        }
                        foreach ($ids as $id) {
                            $label = $attribute->setStoreId($storeId)->getSource()->getOptionText($id);
                            if ($id != null && $label != false) {
                                $items[] = array('id' => $id, 'label' => $label);
                            }
                        }
                    } else {
                        $value = $product->getData($attribute->getAttributeCode());
                        $label = $productAttribute->setStoreId($storeId)->getFrontend()->getOption($value);
                        if ($value != null && $label != false) {
                            $thisItem = array('id' => $value, 'label' => $label);
                            try {
                                if ($this->swatchesHelper->isVisualSwatch($productAttribute)) {
                                    $swatchConfig = $this->swatchesHelper->getSwatchesByOptionsId([$value]);
                                    if (count($swatchConfig) > 0) {
                                        $thisItem['swatch'] = $swatchConfig[$value]['value'];
                                        if (strpos($thisItem['swatch'], '#') === false) {
                                            $thisItem['swatch'] = $this->swatchesMediaHelper->getSwatchAttributeImage('swatch_image', $thisItem['swatch']);
                                        }
                                    }
                                }
                            } catch (\Exception $e) { }
                            $items[] = $thisItem;
                        }
                    }
                    if (count($items) > 0) {
                        array_push($productTags, array("tag_set" => array("id" => $attribute->getAttributeCode(), "label" => $productAttribute->getStoreLabel($storeId), 'type' => $fieldType ),"items" => $items));
                    }
                }
            }
        }
        return $productTags;
    }

    public function getTagSetDetails($storeId, $product, $attributeCode, &$cache) {
        if (isset($cache[$attributeCode])) {
            return $cache[$attributeCode];
        }
        $productAttribute = $product->getResource()->getAttribute($attributeCode);
        $fieldType = $productAttribute->getFrontendInput();
        $label = $productAttribute->getStoreLabel($storeId);
        $tagSet = [
            'id' => $attributeCode,
            'label' => $label,
            'type' => $fieldType
        ];
        $cache[$attributeCode] = $tagSet;
        return $tagSet;
    }

    public function mergeIntoCategoriesTree($categoriesTree, $pathIds) {
        $pathIdsCount = count($pathIds);
        if (!array_key_exists($pathIds[0], $categoriesTree)) {
            $categoriesTree[$pathIds[0]] = array();
        }
        if ($pathIdsCount > 1) {
            $categoriesTree[$pathIds[0]] = $this->mergeIntoCategoriesTree($categoriesTree[$pathIds[0]], array_slice($pathIds, 1));
        }
        return $categoriesTree;
    }

    public function detailsFromCategoryTree($categoriesTree, $storeId) {
        $detailsTree = array();
        foreach($categoriesTree as $categoryId => $subCategoriesTree) {
            try {
                $category = $this->categoryRepository->get($categoryId, $storeId);
            } catch (\Exception $e) {
                continue;
            }
            $thisCategoryDetails = $this->tagalysCategory->getCategoryTag($category);
            $subCategoriesCount = count($subCategoriesTree);
            if ($subCategoriesCount > 0) {
                $thisCategoryDetails['items'] = $this->detailsFromCategoryTree($subCategoriesTree, $storeId);
            }
            array_push($detailsTree, $thisCategoryDetails);
        }
        return $detailsTree;
    }

    public function getProductsToReindex() {
        return $this->productsToReindex;
    }

    public function reindexRequiredProducts() {
        if (count($this->productsToReindex) > 0) {
            $this->tagalysCategory->reindexUpdatedProducts($this->productsToReindex);
            $this->productsToReindex = array();
        }
    }

    public function getProductCategories($product, $storeId) {
        $categoryIds =  $product->getCategoryIds();
        $activeCategoryPaths = array();
        $categoriesAssigned = array();
        foreach ($categoryIds as $key => $categoryId) {
            try {
                // TODO: should we use $storeId instead?
                $category = $this->categoryRepository->get($categoryId, $this->storeManager->getStore()->getId());
            } catch (\Exception $e) {
                continue;
            }
            if ($category->getIsActive()) {
                $path = $category->getPath();
                $activeCategoryPaths[] = $path;
            }
        }
        if (count($categoriesAssigned) > 0) {
            array_push($this->productsToReindex, $product->getId());
        }
        $activeCategoriesTree = array();
        $rootCategoryId = $this->storeManager->getStore()->getRootCategoryId();
        foreach($activeCategoryPaths as $activeCategoryPath) {
            $pathIds = explode('/', $activeCategoryPath);
            if(!in_array($rootCategoryId, $pathIds)) {
                // skip the categories which are not under the root category of this store
                continue;
            }
            // skip the first two levels which are 'Root Catalog' and the Store's root
            $pathIds = array_splice($pathIds, 2);
            if (count($pathIds) > 0) {
                $activeCategoriesTree = $this->mergeIntoCategoriesTree($activeCategoriesTree, $pathIds);
            }
        }
        $activeCategoryDetailsTree = $this->detailsFromCategoryTree($activeCategoriesTree, $storeId);
        return $activeCategoryDetailsTree;
    }

    public function getProductForPrices($product, $storeId) {
        $productForPrices = $product;
        switch($product->getTypeId()) {
            case 'grouped':
                $minSalePrice = null;
                foreach($product->getTypeInstance()->getAssociatedProductIds($product) as $connectedProductId) {
                    $connectedProduct = $this->productFactory->create()->setStoreId($storeId)->load($connectedProductId);
                    $thisSalePrice = $connectedProduct->getFinalPrice();
                    if ($minSalePrice == null || $minSalePrice > $thisSalePrice) {
                        $minSalePrice = $thisSalePrice;
                        $productForPrices = $connectedProduct;
                    }
                }
                break;
        }
        return $productForPrices;
    }

    public function addProductRatingsFields($storeId, $product, $productDetails) {
        $reviews = $this->reviewCollectionFactory->create()->addStoreFilter(
                $storeId
            )->addStatusFilter(
                \Magento\Review\Model\Review::STATUS_APPROVED
            )->addEntityFilter(
                'product', $product->getId()
            )->addRateVotes();
        $avg = 0;
        $productRatings = array();
        foreach($this->ratingCollectionFactory->create() as $rating) {
            $productRatings['id_'.$rating->getId()] = array();
        }
        $productRatingsCount = 0;
        if (count($reviews) > 0) {
            foreach($reviews as $review) {
              foreach($review->getRatingVotes() as $vote) {
                  $productRatings['id_'.$vote->getRatingId()][] = $vote->getPercent();
              }
            }
            $productRatingsCount = count($reviews);
        }
        $productDetails['__magento_ratings_count'] = $productRatingsCount;
        if (count($reviews) > 0) {
            foreach($this->ratingCollectionFactory->create() as $rating) {
                $productDetails['__magento_avg_rating_id_'.$rating->getId()] = round((array_sum($productRatings['id_'.$rating->getId()]) / $productRatingsCount));
            }
        } else {
            foreach($this->ratingCollectionFactory->create() as $rating) {
                $productDetails['__magento_avg_rating_id_'.$rating->getId()] = 0;
            }
        }
        return $productDetails;
    }

    public function addAssociatedProductDetails($product, $productDetails, $storeId){
        $anyAssociatedProductInStock = false;
        $totalInventory = 0;
        $totalAssociatedProducts = 0;
        $productForPrice = $product;
        $minSalePrice = PHP_INT_MAX;

        $nonConfigurableAttributesToInclude = $this->tagalysConfiguration->getConfig("sync:non_configurable_associated_product_attributes_to_include", true, true);
        $nonConfigurableFields = [];
        $nonConfigurableTagSets = [];

        $alreadyRecordedTagIds = [];
        $configurableAttributes = array_map(function ($el) {
            $alreadyRecordedTagIds[$el['attribute_code']] = [];
            return $el['attribute_code'];
        }, $product->getTypeInstance(true)->getConfigurableAttributesAsArray($product));

        $configurableAttributesToGetAllTags = $this->tagalysConfiguration->getConfig('sync:configurable_attributes_to_sync_all_tags', true, true);
        $customAttributeLabels = [];
        foreach($configurableAttributesToGetAllTags as $attribute => $details) {
            $customAttributeLabels[$details['key']] = $details['label'];
        }

        $associatedProducts = $this->linkManagement->getChildren($product->getSku());
        $ids = array();
        foreach($associatedProducts as $p){
            $ids[]=$p->getId();
        }
        // potential optimization: why are we querying the products again through a collection if linkManagement already returns product objects.
        $associatedProducts = $this->productFactory->create()->getCollection()
            // setting flag to include out of stock products: https://magento.stackexchange.com/questions/241709/how-to-get-product-collection-with-both-in-stock-and-out-of-stock-products-in-ma
            ->setFlag('has_stock_status_filter', false)
            ->setStoreId($storeId)
            ->addStoreFilter($storeId)
            ->addAttributeToFilter('status', 1)
            ->addAttributeToFilter('entity_id', array('in' => $ids))
            ->addAttributeToSelect('*');

        if($this->tagalysConfiguration->getConfig("fallback:sync:add_price_data_to_product_collection", true, true)) {
            // we are able to retrieve the correct product prices even without calling this function
            // adding this configuration just in case if we face any side effects by removing this
            $associatedProducts->addFinalPrice();
        }

        $tagItems = array();
        foreach($associatedProducts as $associatedProduct){
            $totalAssociatedProducts += 1;
            $inventoryDetails = $this->getSimpleProductInventoryDetails($associatedProduct);

            // Getting tag sets
            if ($inventoryDetails['in_stock']) {
                $anyAssociatedProductInStock = true;
                $salePrice = $associatedProduct->getPriceInfo()->getPrice('final_price')->getAmount()->getValue();
                if($minSalePrice > $salePrice) {
                    $minSalePrice = $salePrice;
                    $productForPrice = $associatedProduct;
                }
                $totalInventory += $inventoryDetails['qty'];
                foreach($configurableAttributes as $configurableAttribute) {
                    $tagItem = $this->getSingleSelectTagItem($storeId, $associatedProduct, $configurableAttribute, $alreadyRecordedTagIds[$configurableAttribute]);
                    if (!empty($tagItem)) {
                        $tagItems[$configurableAttribute][] = $tagItem;
                    }
                }
                if (!empty($nonConfigurableAttributesToInclude)) {
                    $nonConfigurableAttributes = $this->getProductAttributeValuesFor($storeId, $associatedProduct, $nonConfigurableAttributesToInclude, $alreadyRecordedTagIds);
                    $nonConfigurableFields = array_merge_recursive($nonConfigurableFields, $nonConfigurableAttributes['fields']); // test this with value for 3 products
                    foreach($nonConfigurableAttributes['tag_sets'] as $tag_set_code => $tags) {
                        if(!isset($nonConfigurableTagSets[$tag_set_code])) {
                            $nonConfigurableTagSets[$tag_set_code] = [];
                        }
                        $nonConfigurableTagSets[$tag_set_code] = array_merge($nonConfigurableTagSets[$tag_set_code], $tags);
                    }
                }
            }
            foreach ($configurableAttributes as $configurableAttribute) {
                if (isset($configurableAttributesToGetAllTags[$configurableAttribute])) {
                    $newAttributeDetails = $configurableAttributesToGetAllTags[$configurableAttribute];
                    $tagItem = $this->getSingleSelectTagItem($storeId, $associatedProduct, $configurableAttribute, $alreadyRecordedTagIds[$newAttributeDetails['key']]);
                    if(!empty($tagItem)) {
                        $tagItems[$newAttributeDetails['key']][] = $tagItem;
                    }
                }
            }
        }
        $productDetails['__inventory_total'] = $totalInventory;
        if ($totalAssociatedProducts > 0) {
            $productDetails['__inventory_average'] = round($totalInventory / $totalAssociatedProducts, 2);
        } else {
            $productDetails['__inventory_average'] = 0;
        }

        $considerParenStockValue = $this->tagalysConfiguration->getConfig("sync:consider_parent_in_stock_value", true, true);
        if($considerParenStockValue) {
            $productDetails['in_stock'] =  ($productDetails['in_stock'] && $anyAssociatedProductInStock);
        } else {
            $productDetails['in_stock'] = $anyAssociatedProductInStock;
        }

        // Reformat tag sets
        foreach($tagItems as $configurableAttribute => $items){
            if(isset($customAttributeLabels[$configurableAttribute])) {
                $tagSetLabel = $customAttributeLabels[$configurableAttribute];
            } else {
                $tagSetLabel = $product->getResource()->getAttribute($configurableAttribute)->getStoreLabel($storeId);
            }
            $tagSetData = [
                "tag_set" => [
                    "id" => $configurableAttribute,
                    "label" => $tagSetLabel
                ],
                "items" => $items
            ];
            array_push($productDetails['__tags'], $tagSetData);
        }
        if (!empty($nonConfigurableAttributesToInclude)) {
            foreach($nonConfigurableFields as $key => $value) {
                if (!is_array($value)) {
                    $value = [$value];
                }
                $productDetails[$key] = json_encode($value);
            }
            $tagSetDetailsCache = [];
            foreach($nonConfigurableTagSets as $tagSetCode => $items) {
                $items = Utils::filterDuplicateHashes($items, 'id');
                $tagSet = $this->getTagSetDetails($storeId, $product, $tagSetCode, $tagSetDetailsCache);
                $tagSetData = [
                    "tag_set" => $tagSet,
                    "items" => $items
                ];
                array_push($productDetails['__tags'], $tagSetData);
            }
        }
        return array('details' => $productDetails, 'product_for_price'=>$productForPrice);
    }

    public function getSingleSelectTagItem($storeId, $product, $attributeCode, &$alreadyRecordedTagIds = []) {
        $tagId = $product->getData($attributeCode);
        if ($tagId != NULL && empty($alreadyRecordedTagIds[$tagId])) {
            $thisItem = array('id' => $tagId, 'label' => $product->setStoreId($storeId)->getAttributeText($attributeCode));
            if ($this->isValidTagItem($thisItem)) {
                $attr = $this->productAttributeRepository->get($attributeCode);
                try {
                    if ($this->swatchesHelper->isVisualSwatch($attr)) {
                        $swatchConfig = $this->swatchesHelper->getSwatchesByOptionsId([$tagId]);
                        if (count($swatchConfig) > 0) {
                            $thisItem['swatch'] = $swatchConfig[$tagId]['value'];
                            if (strpos($thisItem['swatch'], '#') === false) {
                                $thisItem['swatch'] = $this->swatchesMediaHelper->getSwatchAttributeImage('swatch_image', $thisItem['swatch']);
                            }
                        }
                    }
                } catch (\Exception $e) {
                }
                $alreadyRecordedTagIds[$tagId] = true;
                return $thisItem;
            }
        }
        return false;
    }

    public function getProductAttributeValuesFor($storeId, $product, $whitelistedAttributeCodes) {
        $attributeValues = [
            'fields' => [],
            'tag_sets' => [],
        ];
        $attributes = $product->getTypeInstance()->getEditableAttributes($product);
        foreach ($attributes as $attribute) {
            $attributeCode = $attribute->getAttributeCode();
            if (!in_array($attributeCode, $whitelistedAttributeCodes)) {
                continue;
            }

            if($this->tagalysConfiguration->isAttributeField($attribute)) {
                $attributeValue = $this->getFieldAttributeValue($storeId, $product, $attribute);
                if (!is_null($attributeValue)) {
                    $attributeValues['fields'][$attributeCode] = $attributeValue;
                }
            }
            if($this->tagalysConfiguration->isAttributeTagSet($attribute)) {
                $tags = $this->getTagSetAttributeValues($storeId, $product, $attribute);
                if (!empty($tags)) {
                    $attributeValues['tag_sets'][$attributeCode] = $tags;
                }
            }
        }
        return $attributeValues;
    }

    public function getFieldAttributeValue($storeId, $product, $attribute) {
        $isBoolean = $attribute->getFrontendInput() == 'boolean';
        if($isBoolean) {
            return $this->getBooleanAttributeValue($storeId, $product, $attribute);
        } else {
            $value = $attribute->getFrontend()->getValue($product);
            if ($value != false) {
                return $value;
            }
        }
        return null;
    }

    public function getTagSetAttributeValues($storeId, $product, $attribute) {
        $attributeCode = $attribute->getAttributeCode();
        $productAttribute = $product->getResource()->getAttribute($attributeCode);
        // select, multi-select
        $fieldType = $productAttribute->getFrontendInput();
        $items = array();
        if ($fieldType == 'multiselect') {
            $items[] = $this->getMultiSelectTagItems($storeId, $product, $attributeCode);
        } else {
            $tagItem = $this->getSingleSelectTagItem($storeId, $product, $attributeCode);
            if($tagItem) {
                $items[] = $tagItem;
            }
        }
        return $items;
    }

    public function getMultiSelectTagItems($storeId, $product, $attributeCode) {
        $items = [];
        $value = $product->getData($attributeCode);
        $tagIds = explode(',', $value);
        foreach ($tagIds as $tagId) {
            $tagItem = [
                'id' => $tagId,
                'label' => $product->setStoreId($storeId)->getAttributeText($attributeCode)
            ];
            if($this->isValidTagItem($tagItem)) {
                $items[] = $tagItem;
            }
        }
        return $items;
    }

    public function isValidTagItem($tagItem) {
        return ($tagItem && $tagItem['id'] != null && $tagItem['label'] != false);
    }

    public function addPriceDetails($product, $productDetails) {
        $store = $this->storeManager->getStore();
        $baseCurrency = $store->getBaseCurrencyCode();
        $allowedCurrencies = $store->getAvailableCurrencyCodes(true);
        if($this->tagalysConfiguration->getConfig('fallback:force_allowed_currencies_to_null', true, true)) {
            $allowedCurrencies = null;
        }
        $baseCurrencyNotAllowed = ($allowedCurrencies == null || !in_array($baseCurrency, $allowedCurrencies));
        $productDetails['scheduled_updates'] = [];
        if (Utils::isBundleProduct($product)) {
            // already returning price in base currency. no conversion needed.
            $useMinTotalPricesForBundles = $this->tagalysConfiguration->getConfig('sync:use_min_total_prices_for_bundles', true, true);
            $useOldMethodToGetBundlePriceValues = $this->tagalysConfiguration->getConfig('fallback:use_old_method_to_get_bundle_prices', true, true);
            if ($useOldMethodToGetBundlePriceValues) {
                if($useMinTotalPricesForBundles){
                    $productDetails['price'] = $product->getPriceModel()->getTotalPrices($product, 'min', 1);
                } else {
                    $productDetails['price'] = $product->getPriceModel()->getTotalPrices($product, 'max', 1);
                }
                $productDetails['sale_price'] = $productDetails['price'];
            } else {
                $regularPriceModel = $product->getPriceInfo()->getPrice('regular_price');
                $finalPriceModel = $product->getPriceInfo()->getPrice('final_price');
                if($useMinTotalPricesForBundles){
                    $productDetails['price'] = $regularPriceModel->getMinimalPrice()->getValue();
                    $productDetails['sale_price'] = $finalPriceModel->getMinimalPrice()->getValue();
                } else {
                    $productDetails['price'] = $regularPriceModel->getMaximalPrice()->getValue();
                    $productDetails['sale_price'] = $finalPriceModel->getMaximalPrice()->getValue();
                }
            }
        } else {
            $useNewMethodToGetPriceValues = $this->tagalysConfiguration->getConfig('sync:use_get_final_price_for_sale_price', true, true);
            if($useNewMethodToGetPriceValues){
                // returns values in base currency. Includes the catalog price rule and special price.
                $productDetails['price'] = $product->getPrice();
                $productDetails['sale_price'] = $product->getFinalPrice();
            } else {
                // https://magento.stackexchange.com/a/152692/80853
                // returns values in current currency (set to base currency if base is in allowed currencies).
                $productDetails['price'] = $product->getPriceInfo()->getPrice('regular_price')->getAmount()->getValue();
                $productDetails['sale_price'] = $product->getPriceInfo()->getPrice('final_price')->getAmount()->getValue();
                if($baseCurrencyNotAllowed){
                    $productDetails['price'] = $this->getPriceInBaseCurrency($productDetails['price']);
                    $productDetails['sale_price'] = $this->getPriceInBaseCurrency($productDetails['sale_price']);
                }
            }
            /** Changing productForPrices->product (check if works) */
            if ($product->getSpecialFromDate() != null) {
                $specialPriceFromDatetime = new \DateTime($product->getSpecialFromDate(), new \DateTimeZone($this->timezoneInterface->getConfigTimezone()));
                $currentDatetime = new \DateTime("now", new \DateTimeZone('UTC'));
                if ($currentDatetime->getTimestamp() >= $specialPriceFromDatetime->getTimestamp()) {
                    if ($product->getSpecialToDate() != null) {
                        $specialPriceToDatetime = new \DateTime($product->getSpecialToDate(), new \DateTimeZone($this->timezoneInterface->getConfigTimezone()));
                        if ($currentDatetime->getTimestamp() <= ($specialPriceToDatetime->getTimestamp() + 24*60*60 - 1)) {
                            // sale price is currently valid. record to date
                            array_push($productDetails['scheduled_updates'], array('at' => str_replace('00:00:00', '23:59:59', $specialPriceToDatetime->format('Y-m-d H:i:sP')), 'updates' => array('sale_price' => $productDetails['price'])));
                        } else {
                            // sale is past expiry; don't record from/to datetimes
                        }
                    } else {
                        // sale price is valid indefinitely; make no changes;
                    }
                } else {
                    // future sale - record other sale price and from/to datetimes
                    $specialPrice = $product->getSpecialPrice();
                    if ($specialPrice != null && $specialPrice > 0) {
                        if($baseCurrencyNotAllowed){
                            $specialPrice = $this->getPriceInBaseCurrency($specialPrice);
                        }
                        $specialPriceFromDatetime = new \DateTime($product->getSpecialFromDate(), new \DateTimeZone($this->timezoneInterface->getConfigTimezone()));
                        array_push($productDetails['scheduled_updates'], array('at' => $specialPriceFromDatetime->format('Y-m-d H:i:sP'), 'updates' => array('sale_price' => $specialPrice)));
                        if ($product->getSpecialToDate() != null) {
                            $specialPriceToDatetime = new \DateTime($product->getSpecialToDate(), new \DateTimeZone($this->timezoneInterface->getConfigTimezone()));
                            array_push($productDetails['scheduled_updates'], array('at' => str_replace('00:00:00', '23:59:59', $specialPriceToDatetime->format('Y-m-d H:i:sP')), 'updates' => array('sale_price' => $productDetails['price'])));
                        }
                    }
                }
            }
        }
        if(($productDetails['price'] == 0) && (Utils::isGiftCard($product) || Utils::isGroupedProduct($product))) {
            $productDetails['price'] = $productDetails['sale_price'];
        }
        return $productDetails;
    }

    public function addSyncedAtTime($productDetails) {
        $utcNow = new \DateTime("now", new \DateTimeZone('UTC'));
        $timeNow =  $utcNow->format(\DateTime::ATOM);
        $productDetails['synced_at'] = $timeNow;
        return $productDetails;
    }

    public function dispatchProductDetails($productDetails) {
        $productDetailsObj = new \Magento\Framework\DataObject(array('product_details' => $productDetails));
        $this->eventManager->dispatch('tagalys_read_product_details', ['tgls_data' => $productDetailsObj]);
        $productDetails = $productDetailsObj->getProductDetails();
        return $productDetails;
    }

    public function gpd($productId, $storeId, $forceRegenerateThumbnail = false) {
        $product = $this->productFactory->create()->setStoreId($storeId)->load($productId);
        return $this->productDetails($product, $storeId, $forceRegenerateThumbnail);
    }

    public function productDetails($product, $storeId, $forceRegenerateThumbnail = false) {
        return $this->tagalysConfiguration->processInStoreContext($storeId, function() use ($product, $storeId, $forceRegenerateThumbnail) {
            // FIXME: stockRegistry deprecated
            $stockItem = $this->stockRegistry->getStockItem($product->getId());
            $productForPrice = $product;
            $productDetails = array(
                '__id' => $product->getId(),
                '__magento_type' => $product->getTypeId(),
                'name' => $product->getName(),
                'link' => $product->getProductUrl(),
                'sku' => $product->getSku(),
                'scheduled_updates' => array(),
                'introduced_at' => date(\DateTime::ATOM, strtotime($product->getCreatedAt())),
                // potential optimization: the below line doesn't need to run for configurable products
                'in_stock' => $stockItem->getIsInStock(),
                'image_url' => $this->getProductImageUrl($storeId, $this->tagalysConfiguration->getConfig('product_image_attribute'), true, $product, $forceRegenerateThumbnail),
                '__tags' => $this->getDirectProductTags($product, $storeId)
            );

            $productDetails = array_merge($productDetails, $this->getProductFields($product));

            if ($productDetails['__magento_type'] == 'simple') {
                $inventoryDetails = $this->getSimpleProductInventoryDetails($product, $stockItem);
                $productDetails['in_stock'] = $inventoryDetails['in_stock'];
                $productDetails['__inventory_total'] = $inventoryDetails['qty'];
                $productDetails['__inventory_average'] = $inventoryDetails['qty'];
            }

            if ($productDetails['__magento_type'] == 'configurable') {
                $result = $this->addAssociatedProductDetails($product, $productDetails, $storeId);
                $productDetails = $result['details'];
                $productForPrice = $result['product_for_price'];
            }

            if ($productDetails['__magento_type'] == 'grouped') {
                $productForPrice = $this->getProductForPrices($product, $storeId);
                $useIsSaleable = $this->tagalysConfiguration->getConfig("sync:use_is_saleable_for_in_stock", true, true);
                $productDetails['in_stock'] = $useIsSaleable ? $product->isSaleable() : $product->isAvailable();
            }

            $productDetails = $this->addProductRatingsFields($storeId, $product, $productDetails);

            $imageHoverAttr = $this->tagalysConfiguration->getConfig('product_image_hover_attribute', false, true);
            if ($imageHoverAttr != '') {
                $productDetails['image_hover_url'] = $this->getProductImageUrl($storeId, $imageHoverAttr, false, $product, $forceRegenerateThumbnail);
            }

            // synced_at
            $productDetails = $this->addSyncedAtTime($productDetails);

            // prices and sale price from/to
            $productDetails = $this->addPriceDetails($productForPrice, $productDetails);

            // New
            $currentDatetime = new \DateTime("now", new \DateTimeZone('UTC'));
            $productDetails['__new'] = false;
            if ($product->getNewsFromDate() != null) {
                $newFromDatetime = new \DateTime($product->getNewsFromDate(), new \DateTimeZone($this->timezoneInterface->getConfigTimezone()));
                if ($currentDatetime->getTimestamp() >= $newFromDatetime->getTimestamp()) {
                    if ($product->getNewsToDate() != null) {
                        $newToDatetime = new \DateTime($product->getNewsToDate(), new \DateTimeZone($this->timezoneInterface->getConfigTimezone()));
                        if ($currentDatetime->getTimestamp() <= ($newToDatetime->getTimestamp() + 24*60*60 - 1)) {
                            // currently new. record to date
                            $productDetails['__new'] = true;
                            array_push($productDetails['scheduled_updates'], array('at' => str_replace('00:00:00', '23:59:59', $newToDatetime->format('Y-m-d H:i:sP')), 'updates' => array('__new' => false)));
                        } else {
                            // new is past expiry; don't record from/to datetimes
                        }
                    } else {
                        // new is valid indefinitely
                        $productDetails['__new'] = true;
                    }
                } else {
                    // new in the future - record from/to datetimes
                    array_push($productDetails['scheduled_updates'], array('at' => $newFromDatetime->format('Y-m-d H:i:sP'), 'updates' => array('__new' => true)));
                    if ($product->getNewsToDate() != null) {
                        $newToDatetime = new \DateTime($product->getNewsToDate(), new \DateTimeZone($this->timezoneInterface->getConfigTimezone()));
                        array_push($productDetails['scheduled_updates'], array('at' => str_replace('00:00:00', '23:59:59', $newToDatetime->format('Y-m-d H:i:sP')), 'updates' => array('__new' => false)));
                    }
                }
            } else {
                // no from date. assume applicable from now
                if ($product->getNewsToDate() != null) {
                    $newToDatetime = new \DateTime($product->getNewsToDate(), new \DateTimeZone($this->timezoneInterface->getConfigTimezone()));
                    if ($currentDatetime->getTimestamp() <= ($newToDatetime->getTimestamp() + 24*60*60 - 1)) {
                        // currently new. record to date
                        $productDetails['__new'] = true;
                        array_push($productDetails['scheduled_updates'], array('at' => str_replace('00:00:00', '23:59:59', $newToDatetime->format('Y-m-d H:i:sP')), 'updates' => array('__new' => false)));
                    } else {
                        // new is past expiry; don't record from/to datetimes
                    }
                }
            }

            $productDetails = $this->dispatchProductDetails($productDetails);
            return $productDetails;
        }, "product_details");
    }

    public function isInStock($product, $scopeId = null) {
        // does not consider MSI. Ok for now.
        $useIsSaleable = $this->tagalysConfiguration->getConfig("sync:use_is_saleable_for_in_stock", true, true);
        $inStock = $useIsSaleable ? $product->isSaleable() : $product->isAvailable();
        if(Utils::isConfigurableProduct($product)) {
            $considerParenStockValue = $this->tagalysConfiguration->getConfig("sync:consider_parent_in_stock_value", true, true);
            if($considerParenStockValue) {
                $inStock = ($inStock && $this->stockRegistry->getStockItem($product->getId(), $scopeId)->getIsInStock());
            }
        }
        return $inStock;
    }

    // TODO: Check if this works with MSI
    public function getSelectiveProductDetails($storeId, $product) {
        return $this->tagalysConfiguration->processInStoreContext($storeId, function () use ($storeId, $product) {

            $productDetails = [
                '__id' => $product->getId(),
                '__magento_type' => $product->getTypeId(),
                'in_stock' => $this->isInStock($product)
            ];

            $productDetails = $this->addSyncedAtTime($productDetails);
            $productDetails = $this->addPriceDetails($product, $productDetails);
            $productDetails = $this->dispatchProductDetails($productDetails);

            return $productDetails;
        });
    }

    public function getPriceInBaseCurrency($amount, $store = null){
        if(!isset($store)){
            $store = $this->storeManager->getStore();
        }
        // cannot use $store->getCurrentCurrency()->convert() because magento does not support converting from currency X to base currency.
        $rate = $store->getCurrentCurrencyRate();
        $amount = $amount / $rate;
        return $amount;
    }

    public function getSimpleProductInventoryDetails($product, $stockItem = false) {
        if($product->getTypeId() == 'simple' || $product->getTypeId() == 'virtual') {
            $magentoVersion = $this->productMetadata->getVersion();
            $msiUsed = $this->tagalysConfiguration->getConfig('sync:multi_source_inventory_used', true);
            if(version_compare($magentoVersion, '2.3.0', '>=') && $msiUsed) {
                // only do this if MSI is used, coz the else part will work for non MSI stores and is faster too.
                $websiteCode = $this->storeManager->getWebsite()->getCode();
                $stockResolver = Utils::getInstanceOf("\Magento\InventorySalesApi\Api\StockResolverInterface");
                $isProductSalableInterface = Utils::getInstanceOf("\Magento\InventorySalesApi\Api\IsProductSalableInterface");
                $getProductSalableQty = Utils::getInstanceOf("\Magento\InventorySalesApi\Api\GetProductSalableQtyInterface");
                $stockId = $stockResolver->execute(\Magento\InventorySalesApi\Api\Data\SalesChannelInterface::TYPE_WEBSITE, $websiteCode)->getStockId();

                $stockQty = $getProductSalableQty->execute($product->getSku(), $stockId);
                $inStock = $isProductSalableInterface->execute($product->getSku(), $stockId);
            } else {
                if($stockItem == false) {
                    $stockItem = $this->stockRegistry->getStockItem($product->getId());
                }
                $stockQty = $stockItem->getQty();
                $inStock = $stockItem->getIsInStock();
            }
            return [
                'in_stock' => $inStock,
                'qty' => (int)$stockQty
            ];
        }
        return false;
    }

    // called while getting product details during "add to cart" or "buy", from Details.php
    public function getAssociatedProductToTrack($product) {
        if($this->isProductVisible($product)) {
            return $product;
        }
        $parentProduct = $this->getConfigurableParent($product);
        if($parentProduct) {
            $mainConfigurableAttribute = $this->tagalysConfiguration->getConfig('analytics:main_configurable_attribute');
            if(!empty($mainConfigurableAttribute)) {
                // If associated simple products are visible individually, find which one is visible and return that
                $siblings = $this->getVisibleChildren($parentProduct);
                if(count($siblings) > 0) {
                    foreach($siblings as $sibling) {
                        if($sibling->getData($mainConfigurableAttribute) == $product->getData($mainConfigurableAttribute)) {
                            return $sibling;
                        }
                    }
                    return $siblings[0];
                }
            }
            // only the configurable products are visible in the front-end, so return that
            return $parentProduct;
        }
        return $product;
    }

    public function getVisibleChildren($parent) {
        $visibleChildren = [];
        if($parent->getTypeId() == 'configurable') {
            $children = $this->linkManagement->getChildren($parent->getSku());
            foreach($children as $child) {
                if($this->isProductVisible($child)) {
                    $visibleChildren[] = $child;
                }
            }
        }
        return $visibleChildren;
    }

    public function getConfigurableParent($child) {
        $parentIds = $this->configurableProduct->getParentIdsByChild($child->getId());
        if(count($parentIds) > 0) {
            return $this->productFactory->create()->load($parentIds[0]);
        }
        return false;
    }

    public function isProductVisible($product) {
        return ($product->getVisibility() != 1);
    }

    private function runSqlSelect($sql){
        $conn = $this->resourceConnection->getConnection();
        return $conn->fetchAll($sql);
    }

    public function getBooleanAttrValueForAPI($storeId, $productId){
        $product = $this->productFactory->create()->load($productId);
        return $this->tagalysConfiguration->processInStoreContext($storeId, function() use($storeId, $product) {
            $attributes = $product->getTypeInstance()->getEditableAttributes($product);
            $attributeValue = [];
            foreach ($attributes as $attribute) {
                $shouldSyncAttribute = $this->tagalysConfiguration->shouldSyncAttribute($attribute);
                if ($shouldSyncAttribute && $attribute->getFrontendInput() == 'boolean') {
                    $attributeValue[$attribute->getAttributeCode()] = [
                        $attribute->getFrontend()->getValue($product),
                        $product->getAttributeText($attribute->getAttributeCode()),
                        $this->getBooleanAttributeValueViaDb($storeId, $product->getId(), $attribute->getAttributeId())
                    ];
                }
            }
            return $attributeValue;
        });
    }

    public function getIdsBySku($skus) {
        $conn = $this->resourceConnection->getConnection();
        $cpe = $this->resourceConnection->getTableName('catalog_product_entity');
        $select = $conn->select()->from($cpe, ['entity_id as product_id', 'sku'])->where('sku IN (?)', $skus);
        return $this->runSqlSelect($select);
    }

}
