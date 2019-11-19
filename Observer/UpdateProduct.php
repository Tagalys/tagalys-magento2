<?php
namespace Tagalys\Sync\Observer;

class UpdateProduct implements \Magento\Framework\Event\ObserverInterface
{
    public function __construct(
        \Tagalys\Sync\Helper\Queue $queueHelper,
        \Tagalys\Sync\Helper\Category $tagalysCategory
    )
    {
        $this->queueHelper = $queueHelper;
        $this->tagalysCategory = $tagalysCategory;
    }
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        try {
            $product = $observer->getProduct();
            $this->queueHelper->insertUnique($product->getId());
            $categoryIds = null;
            try{
                $categoryIds = $product->getCategoryIds();
            } catch(Exception $ignored) {}
            $this->tagalysCategory->pushDownProductsIfRequired(array($product->getId()), $categoryIds, 'product');
        } catch (\Exception $e) { }
    }

}