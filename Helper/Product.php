<?php
namespace Tagalys\Sync\Helper;

class Product extends \Magento\Framework\App\Helper\AbstractHelper
{
    private $productsToReindex = array();
    /**
     * @param \Magento\Framework\App\ResourceConnection
     */
    private $resourceConnection;

    /**
     * @param \Tagalys\Sync\Helper\Configuration
     */
    private $tagalysConfiguration;

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
        \Magento\Framework\App\ResourceConnection $resourceConnection
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
                            return $this->getPlaceholderImageUrl($imageAttributeCode, $allowPlaceholder);
                        }
                    }
                } else {
                    return $this->getPlaceholderImageUrl($imageAttributeCode, $allowPlaceholder);
                }
            } else {
                return $this->getPlaceholderImageUrl($imageAttributeCode, $allowPlaceholder);
            }
        } catch(\Exception $e) {
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
            if($this->tagalysConfiguration->isAttributeField($attribute)) {
                $shouldSyncAttribute = $this->tagalysConfiguration->shouldSyncAttribute($attribute, $whitelistedAttributes, $attributesToIgnore);
                if($shouldSyncAttribute) {
                    $isBoolean = $attribute->getFrontendInput() == 'boolean';
                    if($isBoolean) {
                        $productFields[$attribute->getAttributeCode()] = $this->getBooleanAttributeValue($storeId, $product, $attribute);
                    } else {
                        $attributeValue = $attribute->getFrontend()->getValue($product);
                        if (!is_null($attributeValue)) {
                            if ($isBoolean) {
                                $productFields[$attribute->getAttributeCode()] = ($attributeValue == 'Yes');
                            } else {
                                $productFields[$attribute->getAttributeCode()] = $attributeValue;
                            }
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
                        $ids = explode(',', $value);
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
            $categoryEnabled = (($category->getIsActive() === true || $category->getIsActive() === '1') ? true : false);
            $categoryIncludedInMenu = (($category->getIncludeInMenu() === true || $category->getIncludeInMenu() === '1') ? true : false);
            $thisCategoryDetails = array("id" => $category->getId() , "label" => $category->getName(), "is_active" => $categoryEnabled, "include_in_menu" => $categoryIncludedInMenu);
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

                // assign to parent categories
                $relevantCategories = array_slice(explode('/', $path), 2); // ignore level 0 and 1
                $idsToAssign = array_diff($relevantCategories, $categoryIds);
                foreach ($idsToAssign as $key => $categoryId) {
                    if (!in_array($categoryId, $categoriesAssigned)) {
                        $this->tagalysCategory->assignProductToCategoryViaDb($categoryId, $product);
                        array_push($categoriesAssigned, $categoryId);
                    }
                }
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

        $configurableAttributes = array_map(function ($el) {
            return $el['attribute_code'];
        }, $product->getTypeInstance(true)->getConfigurableAttributesAsArray($product));
        $associatedProducts = $this->linkManagement->getChildren($product->getSku());
        $ids = array();
        foreach($associatedProducts as $p){
            $ids[]=$p->getId();
        }
        // potential optimization: why are we querying the products again through a collection if linkManagement already returns product objects.
        $associatedProducts = $this->productFactory->create()->getCollection()
            ->setStoreId($storeId)
            ->addStoreFilter($storeId)
            ->addAttributeToFilter('status', 1)
            ->addAttributeToFilter('entity_id', array('in' => $ids))
            ->addFinalPrice()
            ->addAttributeToSelect('*');

        $tagItems = array();
        $hash = array();
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
                    $id = $associatedProduct->getData($configurableAttribute);
                    if(!isset($hash[$id])) {
                        $hash[$id] = true;
                        $thisItem = array('id' => $id, 'label' => $associatedProduct->setStoreId($storeId)->getAttributeText($configurableAttribute));
                        $attr = $this->productAttributeRepository->get($configurableAttribute);
                        try {
                            if ($this->swatchesHelper->isVisualSwatch($attr)) {
                                $swatchConfig = $this->swatchesHelper->getSwatchesByOptionsId([$id]);
                                if (count($swatchConfig) > 0) {
                                    $thisItem['swatch'] = $swatchConfig[$id]['value'];
                                    if (strpos($thisItem['swatch'], '#') === false) {
                                        $thisItem['swatch'] = $this->swatchesMediaHelper->getSwatchAttributeImage('swatch_image', $thisItem['swatch']);
                                    }
                                }
                            }
                        } catch (\Exception $e) { }
                        $tagItems[$configurableAttribute][] = $thisItem;
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

        $productDetails['in_stock'] = $anyAssociatedProductInStock;

        // Reformat tag sets
        foreach($tagItems as $configurableAttribute => $items){
            array_push($productDetails['__tags'], array("tag_set" => array("id" => $configurableAttribute, "label" => $product->getResource()->getAttribute($configurableAttribute)->getStoreLabel($storeId)), "items" => $items));
        }
        return array('details' => $productDetails, 'product_for_price'=>$productForPrice);
    }

    public function productDetails($product, $storeId, $forceRegenerateThumbnail = false) {
        if (!is_object($product)) {
            $product = $this->productFactory->create()->setStoreId($storeId)->load($product);
        }
        $originalStoreId = $this->storeManager->getStore()->getId();
        $originalCurrency = $this->storeManager->getStore()->getCurrentCurrencyCode();
        $this->storeManager->setCurrentStore($storeId);
        $store = $this->storeManager->getStore();
        $baseCurrency = $store->getBaseCurrencyCode();
        $allowedCurrencies = $store->getAvailableCurrencies(true);
        $baseCurrencyNotAllowed = ($allowedCurrencies==null || !in_array($baseCurrency, $allowedCurrencies));
        $useNewMethodToGetPriceValues = $this->tagalysConfiguration->getConfig('sync:use_get_final_price_for_sale_price', true);
        $store->setCurrentCurrencyCode($baseCurrency);
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
        }

        $productDetails = $this->addProductRatingsFields($storeId, $product, $productDetails);

        if ($this->tagalysConfiguration->getConfig("product_image_hover_attribute") != '') {
            $productDetails['image_hover_url'] = $this->getProductImageUrl($storeId, $this->tagalysConfiguration->getConfig('product_image_hover_attribute'), false, $product, $forceRegenerateThumbnail);
        }

        $productDetails = array_merge($productDetails, $this->getProductFields($product));

        // synced_at
        $utcNow = new \DateTime("now", new \DateTimeZone('UTC'));
        $timeNow =  $utcNow->format(\DateTime::ATOM);
        $productDetails['synced_at'] = $timeNow;

        // prices and sale price from/to
        if ($product->getTypeId() == 'bundle') {
            // already returning price in base currency. no conversion needed.
            $productDetails['price'] = $product->getPriceModel()->getTotalPrices($product, 'min', 1);
            $productDetails['sale_price'] = $product->getPriceModel()->getTotalPrices($product, 'min', 1);
        } else {
            if($useNewMethodToGetPriceValues){
                // returns values in base currency. Includes the catalog price rule and special price.
                $productDetails['price'] = $productForPrice->getPrice();
                $productDetails['sale_price'] = $productForPrice->getFinalPrice();
            } else {
                // https://magento.stackexchange.com/a/152692/80853
                // returns values in current currency (set to base currency if base is in allowed currencies).
                $productDetails['price'] = $productForPrice->getPriceInfo()->getPrice('regular_price')->getAmount()->getValue();
                $productDetails['sale_price'] = $productForPrice->getPriceInfo()->getPrice('final_price')->getAmount()->getValue();
                if($baseCurrencyNotAllowed){
                    $productDetails['price'] = $this->getPriceInBaseCurrency($productDetails['price']);
                    $productDetails['sale_price'] = $this->getPriceInBaseCurrency($productDetails['sale_price']);
                }
            }
            /** Changing productForPrices->product (check if works) */
            if ($productForPrice->getSpecialFromDate() != null) {
                $specialPriceFromDatetime = new \DateTime($productForPrice->getSpecialFromDate(), new \DateTimeZone($this->timezoneInterface->getConfigTimezone()));
                $currentDatetime = new \DateTime("now", new \DateTimeZone('UTC'));
                if ($currentDatetime->getTimestamp() >= $specialPriceFromDatetime->getTimestamp()) {
                    if ($productForPrice->getSpecialToDate() != null) {
                        $specialPriceToDatetime = new \DateTime($productForPrice->getSpecialToDate(), new \DateTimeZone($this->timezoneInterface->getConfigTimezone()));
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
                    $specialPrice = $productForPrice->getSpecialPrice();
                    if ($specialPrice != null && $specialPrice > 0) {
                        if($baseCurrencyNotAllowed){
                            $specialPrice = $this->getPriceInBaseCurrency($specialPrice);
                        }
                        $specialPriceFromDatetime = new \DateTime($productForPrice->getSpecialFromDate(), new \DateTimeZone($this->timezoneInterface->getConfigTimezone()));
                        array_push($productDetails['scheduled_updates'], array('at' => $specialPriceFromDatetime->format('Y-m-d H:i:sP'), 'updates' => array('sale_price' => $specialPrice)));
                        if ($productForPrice->getSpecialToDate() != null) {
                            $specialPriceToDatetime = new \DateTime($productForPrice->getSpecialToDate(), new \DateTimeZone($this->timezoneInterface->getConfigTimezone()));
                            array_push($productDetails['scheduled_updates'], array('at' => str_replace('00:00:00', '23:59:59', $specialPriceToDatetime->format('Y-m-d H:i:sP')), 'updates' => array('sale_price' => $productDetails['price'])));
                        }
                    }
                }
            }
        }

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

        $productDetailsObj = new \Magento\Framework\DataObject(array('product_details' => $productDetails));
        $this->eventManager->dispatch('tagalys_read_product_details', ['tgls_data' => $productDetailsObj]);
        $productDetails = $productDetailsObj->getProductDetails();

        $this->storeManager->setCurrentStore($originalStoreId);
        $this->storeManager->getStore()->setCurrentCurrencyCode($originalCurrency);

        return $productDetails;
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
                $stockResolver = Configuration::getInstanceOf("\Magento\InventorySalesApi\Api\StockResolverInterface");
                $isProductSalableInterface = Configuration::getInstanceOf("\Magento\InventorySalesApi\Api\IsProductSalableInterface");
                $getProductSalableQty = Configuration::getInstanceOf("\Magento\InventorySalesApi\Api\GetProductSalableQtyInterface");
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
