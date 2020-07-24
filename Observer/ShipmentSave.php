<?php

namespace Tagalys\Sync\Observer;

class ShipmentSave implements \Magento\Framework\Event\ObserverInterface
{
    public function __construct(
        \Tagalys\Sync\Helper\Queue $queueHelper,
        \Tagalys\Sync\Helper\Category $tagalysCategory
    ) {
        $this->queueHelper = $queueHelper;
        $this->tagalysCategory = $tagalysCategory;
    }
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        try {
            $updatedProductIds = [];
            $order = $observer->getEvent()->getShipment()->getOrder();
            $items = $order->getAllVisibleItems();
            foreach ($items as $item) {
                $updatedProductIds[] = $item->getProduct()->getId();
            }
            $this->queueHelper->insertUnique($updatedProductIds);
        } catch (\Throwable $e) { }
    }
}
