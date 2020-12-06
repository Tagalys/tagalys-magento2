<?php
namespace Tagalys\Sync\Observer;

class UpdateProduct implements \Magento\Framework\Event\ObserverInterface
{
    public function __construct(
        \Tagalys\Sync\Helper\Queue $queueHelper,
        \Tagalys\Sync\Helper\Category $tagalysCategory,
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration
    )
    {
        $this->queueHelper = $queueHelper;
        $this->tagalysCategory = $tagalysCategory;
        $this->tagalysConfiguration = $tagalysConfiguration;
    }
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        try {
            $productUpdateDetectionMethods = $this->tagalysConfiguration->getConfig('product_update_detection_methods', true);
            if (in_array('events', $productUpdateDetectionMethods)) {
                $product = $observer->getProduct();
                $this->queueHelper->insertIfRequired($product->getId());
                $categoryIds = null;
                try{
                    $categoryIds = $product->getCategoryIds();
                } catch(\Throwable $ignored) {}
                $this->tagalysCategory->pushDownProductsIfRequired(array($product->getId()), $categoryIds, 'product');
            }
        } catch (\Throwable $e) { }
    }

}
