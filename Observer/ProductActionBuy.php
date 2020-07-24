<?php
namespace Tagalys\Sync\Observer;

class ProductActionBuy implements \Magento\Framework\Event\ObserverInterface
{
    public function __construct(
        \Magento\Sales\Model\Order $order,
        \Magento\ConfigurableProduct\Model\Product\Type\Configurable $configurableProduct,
        \Magento\Catalog\Model\ProductFactory $productFactory,
        \Magento\Framework\Registry $registry
    )
    {
        $this->order = $order;
        $this->configurableProduct = $configurableProduct;
        $this->productFactory = $productFactory;
        $this->registry = $registry;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        try {
            $orderIds = $observer->getEvent()->getOrderIds();
            if (empty($orderIds) || !is_array($orderIds)) {
                return;
            }
            $orderId = $orderIds[0];
            $order = $this->order->load($orderId);
            $analyticsCookieData = array(1 /* cookie format version */, 'product_action', 'buy', array(), $orderId);

            $returnItems = array();
            foreach($order->getAllItems() as $item){
                $qty = $item->getQtyToShip();
                $product = $item->getProduct();
                if ($product->getTypeId() == 'simple') {
                    $parentId = $this->configurableProduct->getParentIdsByChild($product->getId());
                    if(isset($parentId[0])) {
                        $configurableProduct = $this->productFactory->create()->load($parentId[0]);
                        $analyticsCookieData[3][] = array((int)$item->getQtyOrdered(), $configurableProduct->getId(), $product->getId());
                    } else {
                        $analyticsCookieData[3][] = array((int)$item->getQtyOrdered(), $product->getId());
                    }
                }
            }
            $this->registry->register('tagalys_analytics_event', json_encode($analyticsCookieData));
        } catch (\Throwable $e) {

        }
    }
}
