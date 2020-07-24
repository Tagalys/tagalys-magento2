<?php

namespace Tagalys\Sync\Observer;

class RecordUpdatedProducts implements \Magento\Framework\Event\ObserverInterface
{
  public function __construct(
      \Tagalys\Sync\Helper\Queue $queueHelper
  )
  {
      $this->queueHelper = $queueHelper;
  }
  public function execute(\Magento\Framework\Event\Observer $observer)
  {
    try {
        $productsObj = $observer->getData('tgls_data');
        $updatedProductIds = $productsObj->getProductIds();
        $this->queueHelper->insertUnique($updatedProductIds);
    } catch (\Throwable $e) { }
    return $this;
  }
}
