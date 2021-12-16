<?php
namespace Tagalys\Sync\Plugin;

use Tagalys\Sync\Helper\Utils;

class OrderCancel
{
    public function __construct(
        \Tagalys\Sync\Helper\Queue $tagalysQueue,
        \Magento\Sales\Model\OrderRepository $orderRepository
    ) {
        $this->tagalysQueue = $tagalysQueue;
        $this->orderRepository = $orderRepository;
    }

    public function afterCancel(
        \Magento\Sales\Api\OrderManagementInterface $subject,
        $result,
        $id
    ) {
        try{
            $order = $this->orderRepository->get($id);
            $items = $order->getAllItems();
            $productIds = [];
            foreach ($items as $item) {
                $productIds[] = $item->getProductId();
            }
            $this->tagalysQueue->insertUnique($productIds);
        } catch (\Throwable $e) {
        }
        return true;
    }
}
