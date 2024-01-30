<?php
namespace Tagalys\Sync\Observer;

class ProductActionBuy implements \Magento\Framework\Event\ObserverInterface
{
    private $order;
    private $configurableProduct;
    private $productFactory;
    private $sessionManager;
    
    public function __construct(
        \Magento\Sales\Model\Order $order,
        \Magento\ConfigurableProduct\Model\Product\Type\Configurable $configurableProduct,
        \Magento\Catalog\Model\ProductFactory $productFactory,
        \Magento\Framework\Session\SessionManager $sessionManager
    )
    {
        $this->order = $order;
        $this->configurableProduct = $configurableProduct;
        $this->productFactory = $productFactory;
        $this->sessionManager = $sessionManager;
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
            $analyticsData = array(1 /* cookie format version */, 'product_action', 'buy', array(), $orderId);

            $returnItems = array();
            foreach($order->getAllItems() as $item){
                $qty = $item->getQtyToShip();
                $product = $item->getProduct();
                if ($product->getTypeId() == 'simple') {
                    $parentId = $this->configurableProduct->getParentIdsByChild($product->getId());
                    if(isset($parentId[0])) {
                        $configurableProduct = $this->productFactory->create()->load($parentId[0]);
                        $analyticsData[3][] = array((int)$item->getQtyOrdered(), $configurableProduct->getId(), $product->getId());
                    } else {
                        $analyticsData[3][] = array((int)$item->getQtyOrdered(), $product->getId());
                    }
                }
            }
            $this->sessionManager->setData('__tagalys_event', json_encode($analyticsData));
        } catch (\Throwable $e) {

        }
    }
}
