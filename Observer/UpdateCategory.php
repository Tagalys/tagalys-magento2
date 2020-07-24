<?php
namespace Tagalys\Sync\Observer;

class UpdateCategory implements \Magento\Framework\Event\ObserverInterface
{
    public function __construct(
        \Tagalys\Sync\Helper\Queue $queueHelper,
        \Tagalys\Sync\Helper\Category $tagalysCategory,
        \Magento\Framework\Registry $_registry,
        \Tagalys\Sync\Model\CategoryFactory $tagalysCategoryFactory,
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration
    )
    {
        $this->queueHelper = $queueHelper;
        $this->tagalysCategory = $tagalysCategory;
        $this->_registry = $_registry;
        $this->tagalysCategoryFactory = $tagalysCategoryFactory;
        $this->tagalysConfiguration = $tagalysConfiguration;
    }
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        try {
            $category = $observer->getEvent()->getCategory();
            $tagalysCreated = $this->tagalysCategory->isTagalysCreated($category);
            $tagalysContext = $this->_registry->registry("tagalys_context");
            if($tagalysCreated || $tagalysContext){
                return true;
            }
            $this->updateTagalysCategoryStatus($category);
            $products = $category->getPostedProducts();
            $oldProducts = $category->getProductsPosition();
            $insert = array_diff_key($products, $oldProducts);
            $delete = array_diff_key($oldProducts, $products);

            $insertedProductIds = array();
            $modifiedProductIds = array();
            foreach($insert as $productId => $pos) {
                array_push($insertedProductIds, $productId);
                array_push($modifiedProductIds, $productId);
            }
            foreach($delete as $productId => $pos) {
                array_push($modifiedProductIds, $productId);
            }
            $this->queueHelper->insertUnique($modifiedProductIds);
            if (count($insertedProductIds) > 0) {
                $this->tagalysCategory->pushDownProductsIfRequired($insertedProductIds, array($category->getId()), 'category');
                $this->tagalysCategory->categoryUpdateAfter($category);
            }
        } catch (\Throwable $e) { }
    }

    private function updateTagalysCategoryStatus($category){
        $powerAllCategories = ($this->tagalysConfiguration->getConfig('module:listingpages:enabled') == '2');
        if($powerAllCategories){
            $this->tagalysCategory->powerCategoryForAllStores($category);
        } else {
            $categories = $this->tagalysCategoryFactory->create()->getCollection()->addFieldToFilter('category_id', $category->getId());
            foreach($categories as $category) {
                if($category->getStatus()== 'powered_by_tagalys'){
                    $category->setStatus('pending_sync')->save();
                }
            }
        }
    }
}
?>
