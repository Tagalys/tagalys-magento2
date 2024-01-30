<?php
namespace Tagalys\Sync\Observer;

use Tagalys\Sync\Helper\Utils;

class ProductActionAddToCart implements \Magento\Framework\Event\ObserverInterface
{
    private $cookieManager;
    private $logger;
    public function __construct(
        \Tagalys\Sync\CookieManager $cookieManager
    )
    {
        $this->cookieManager = $cookieManager;
        $this->logger = Utils::getLogger("tagalys_custom.log");
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        try {
            $mainProduct = $observer->getEvent()->getProduct();
            $productType = $mainProduct->getTypeId();
            $quoteItem = $observer->getEvent()->getQuoteItem();
            $analyticsData = array(1 /* cookie format version */, 'product_action', 'add_to_cart', array(array($quoteItem->getQtyToAdd(), $mainProduct->getId())));
            if ($productType == 'configurable') {
                $option = $quoteItem->getOptionByCode('simple_product');
                $simpleProduct = $option->getProduct();
                $analyticsData[3][0][] = $simpleProduct->getId();
            }
            $this->cookieManager->set('_tagalys_event', json_encode($analyticsData));
            $this->logger->info(json_encode($analyticsData));
        } catch (\Throwable $e) {

        }
    }
}
