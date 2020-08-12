<?php

namespace Tagalys\Sync\Observer;

class DeleteCategory implements \Magento\Framework\Event\ObserverInterface {
    public function __construct(
        \Tagalys\Sync\Helper\Queue $queueHelper,
        \Tagalys\Sync\Helper\Category $tagalysCategory,
        \Magento\Framework\Registry $_registry,
        \Tagalys\Sync\Model\CategoryFactory $tagalysCategoryFactory
    ) {
        $this->queueHelper = $queueHelper;
        $this->tagalysCategory = $tagalysCategory;
        $this->_registry = $_registry;
        $this->tagalysCategoryFactory = $tagalysCategoryFactory;
    }
    public function execute(\Magento\Framework\Event\Observer $observer) {
        try {
            $category = $observer->getEvent()->getCategory();
            $tagalysCreated = $this->tagalysCategory->isTagalysCreated($category);
            $tagalysContext = $this->_registry->registry("tagalys_context");
            if ($tagalysCreated || $tagalysContext) {
                return true;
            }
            $this->markCategoryForDeletion($category);
            $products = $category->getProductsPosition();
            $modifiedProductIds = array();
            foreach ($products as $productId => $pos) {
                array_push($modifiedProductIds, $productId);
            }
            $this->queueHelper->insertUnique($modifiedProductIds);
        } catch (\Throwable $e) { }
    }

    private function markCategoryForDeletion($category) {
        $tagalysCategories = $this->tagalysCategoryFactory->create()->getCollection()->addFieldToFilter('category_id', $category->getId());
        foreach ($tagalysCategories as $tagalysCategory) {
            $tagalysCategory->setMarkedForDeletion('1')->save();
        }
    }
}
?>
