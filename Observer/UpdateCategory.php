<?php
namespace Tagalys\Sync\Observer;

class UpdateCategory implements \Magento\Framework\Event\ObserverInterface
{
    public function __construct(
        \Tagalys\Sync\Helper\Queue $queueHelper,
        \Tagalys\Sync\Helper\Category $tagalysCategory,
        \Magento\Framework\Registry $_registry,
        \Tagalys\Sync\Model\CategoryFactory $tagalysCategoryFactory
    )
    {
        $this->queueHelper = $queueHelper;
        $this->tagalysCategory = $tagalysCategory;
        $this->_registry = $_registry;
        $this->tagalysCategoryFactory = $tagalysCategoryFactory;
    }
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        try {
            $category = $observer->getEvent()->getCategory();
            $products = $category->getPostedProducts();
            $tagalysCreated = $this->tagalysCategory->isTagalysCreated($category);
            $tagalysContext = $this->_registry->registry("tagalys_context");
            if($tagalysCreated || $tagalysContext){
                return true;
            }
            $this->markPendingSync($category->getId());
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
        } catch (\Exception $e) { }
    }

    private function markPendingSync($categoryId){
        $categories = $this->tagalysCategoryFactory->create()->getCollection()->addFieldToFilter('category_id', $categoryId);
        foreach($categories as $category) {
            if($category->getStatus()== 'powered_by_tagalys'){
                $category->setStatus('pending_sync')->save();
            }
        }
    }
}
