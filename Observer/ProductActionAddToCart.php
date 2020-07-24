<?php
namespace Tagalys\Sync\Observer;

class ProductActionAddToCart implements \Magento\Framework\Event\ObserverInterface
{
    public function __construct(
        \Tagalys\Sync\CookieManager $cookieManager
    )
    {
        $this->cookieManager = $cookieManager;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        try {
            $mainProduct = $observer->getEvent()->getProduct();
            $productType = $mainProduct->getTypeId();
            $quoteItem = $observer->getEvent()->getQuoteItem();
            $analyticsCookieData = array(1 /* cookie format version */, 'product_action', 'add_to_cart', array(array($quoteItem->getQtyToAdd(), $mainProduct->getId())));
            if ($productType == 'configurable') {
                $option = $quoteItem->getOptionByCode('simple_product');
                $simpleProduct = $option->getProduct();
                $analyticsCookieData[3][0][] = $simpleProduct->getId();
            }
            $this->cookieManager->set('__ta_event', json_encode($analyticsCookieData));
        } catch (\Throwable $e) {

        }
    }
}
