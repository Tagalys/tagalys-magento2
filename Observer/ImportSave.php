<?php
namespace Tagalys\Sync\Observer;

class ImportSave implements \Magento\Framework\Event\ObserverInterface
{
    public function __construct(
        \Magento\Catalog\Model\Product $product,
        \Tagalys\Sync\Helper\Queue $queueHelper,
        \Tagalys\Sync\Helper\Category $tagalysCategory
    )
    {
        $this->product = $product;
        $this->queueHelper = $queueHelper;
        $this->tagalysCategory = $tagalysCategory;
    }
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        try {
            $bunch = $observer->getEvent()->getData('bunch');

            $productIds = array();
            foreach($bunch as $bunchItem) {
                $sku = strtolower($bunchItem['sku']);
                $productId = $this->product->getIdBySku($sku);
                array_push($productIds, $productId);
            }

            $this->queueHelper->insertUnique($productIds);

            $this->tagalysCategory->pushDownProductsIfRequired($productIds, null, 'product');
        } catch (\Throwable $e) { }
    }
}
