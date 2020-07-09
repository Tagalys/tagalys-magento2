<?php

namespace Tagalys\Sync\Observer;

class DeleteCategory implements \Magento\Framework\Event\ObserverInterface {
  public function __construct(
    \Tagalys\Sync\Helper\Queue $queueHelper,
    \Tagalys\Sync\Helper\Category $tagalysCategory,
    \Magento\Framework\Registry $_registry,
    \Tagalys\Sync\Model\CategoryFactory $tagalysCategoryFactory,
    \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $categoryCollectionFactory
  ) {
    $this->queueHelper = $queueHelper;
    $this->tagalysCategory = $tagalysCategory;
    $this->_registry = $_registry;
    $this->tagalysCategoryFactory = $tagalysCategoryFactory;
    $this->categoryCollectionFactory = $categoryCollectionFactory;
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
    } catch (\Exception $e) { }
  }

  private function markCategoryForDeletion($category) {
    $subCategories = array_map(function($subCategory) {
        return $subCategory['entity_id'];
    }, $category->getChildrenCategories()->toArray(['entity_id']));
    $tagalysCategories = $this->tagalysCategoryFactory->create()->getCollection();
    foreach ($tagalysCategories as $tagalysCategory) {
        $tagalysCategoryId = $tagalysCategory->getCategoryId();
        if($tagalysCategoryId == $category->getId() || in_array($tagalysCategoryId, $subCategories)) {
            $tagalysCategory->setMarkedForDeletion('1')->save();
        }
    }
  }
}
?>
