<?php
namespace Tagalys\Sync\Helper;

class Product extends \Magento\Framework\App\Helper\AbstractHelper
{
    private $productsToReindex = array();
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
        \Magento\CatalogInventory\Api\StockRegistryInterface $stockRegistry
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
                                unlink($resizedProductImagePath);
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
        foreach ($attributes as $attribute) {
            if (!in_array($attribute->getAttributeCode(), $attributesToIgnore)) {
                $isWhitelisted = false;
                if ((bool)$attribute->getIsUserDefined() == false && in_array($attribute->getAttributeCode(), array('url_key'))) {
                    $isWhitelisted = true;
                }
                $isForDisplay = ((bool)$attribute->getUsedInProductListing() && (bool)$attribute->getIsUserDefined());
                if ($attribute->getIsFilterable() || $attribute->getIsSearchable() || $isForDisplay || $isWhitelisted) {

                    if (!in_array($attribute->getAttributeCode(), array('status', 'tax_class_id')) && $attribute->getFrontendInput() != 'multiselect') {
                        $attributeValue = $attribute->getFrontend()->getValue($product);
                        if (!is_null($attributeValue)) {
                            if ($attribute->getFrontendInput() == 'boolean') {
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

    public function getDirectProductTags($product, $storeId) {
        $productTags = array();

        // categories
        array_push($productTags, array("tag_set" => array("id" => "__categories", "label" => "Categories" ), "items" => $this->getProductCategories($product, $storeId)));

        // other attributes
        $attributes = $product->getTypeInstance()->getEditableAttributes($product);
        foreach ($attributes as $attribute) {
            $isWhitelisted = false;
            if ((bool)$attribute->getIsUserDefined() == false && in_array($attribute->getAttributecode(), array('visibility'))) {
                $isWhitelisted = true;
            }
            $isForDisplay = ((bool)$attribute->getUsedInProductListing() && (bool)$attribute->getIsUserDefined());
            if (!in_array($attribute->getAttributeCode(), array('status', 'tax_class_id')) && !in_array($attribute->getFrontendInput(), array('boolean')) && ($attribute->getIsFilterable() || $attribute->getIsSearchable() || $isForDisplay || $isWhitelisted)) {
                $productAttribute = $product->getResource()->getAttribute($attribute->getAttributeCode());
                if ($productAttribute->usesSource()) {
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
            $indexer = $this->indexerRegistry->get('catalog_product_category');
            $indexer->reindexList($this->productsToReindex);
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
        foreach($activeCategoryPaths as $activeCategoryPath) {
            $pathIds = explode('/', $activeCategoryPath);
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
            $stockItem = $this->stockRegistry->getStockItem($associatedProduct->getId());
            $isInStock = $stockItem->getIsInStock();
            
            // Getting tag sets
            if ($isInStock) {
                $anyAssociatedProductInStock = true;
                $salePrice = $associatedProduct->getPriceInfo()->getPrice('final_price')->getAmount()->getValue();
                if($minSalePrice > $salePrice) {
                    $minSalePrice = $salePrice;
                    $productForPrice = $associatedProduct;
                }
                $totalInventory += (int)$stockItem->getQty();
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
        $originalStoreId = $this->storeManager->getStore()->getId();
        $this->storeManager->setCurrentStore($storeId);
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
            'in_stock' => $stockItem->getIsInStock(),
            'image_url' => $this->getProductImageUrl($storeId, $this->tagalysConfiguration->getConfig('product_image_attribute'), true, $product, $forceRegenerateThumbnail),
            '__tags' => $this->getDirectProductTags($product, $storeId)
        );

        if ($productDetails['__magento_type'] == 'simple') {
            $inventory = (int)$stockItem->getQty();
            $productDetails['__inventory_total'] = $inventory;
            $productDetails['__inventory_average'] = $inventory;
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
            $productDetails['price'] = $product->getPriceModel()->getTotalPrices($product, 'min', 1);
            $productDetails['sale_price'] = $product->getPriceModel()->getTotalPrices($product, 'min', 1);
        } else {
            // https://magento.stackexchange.com/a/152692/80853
            $productDetails['price'] = $productForPrice->getPriceInfo()->getPrice('regular_price')->getAmount()->getValue();
            $productDetails['sale_price'] = $productForPrice->getPriceInfo()->getPrice('final_price')->getAmount()->getValue();
            // ___
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
                        $specialPriceFromDatetime = new \DateTime($productForPrice->getSpecialFromDate(), new \DateTimeZone($this->timezoneInterface->getConfigTimezone()));
                        array_push($productDetails['scheduled_updates'], array('at' => $specialPriceFromDatetime->format('Y-m-d H:i:sP'), 'updates' => array('sale_price' => $productForPrice->getSpecialPrice())));
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

        return $productDetails;
    }
}