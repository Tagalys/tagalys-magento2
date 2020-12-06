<?php
namespace Tagalys\Sync\Observer;

class UpdateAttributes implements \Magento\Framework\Event\ObserverInterface
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
            $updatedProductIds = $observer->getEvent()->getProductIds();
            $this->queueHelper->insertIfRequired($updatedProductIds);
        } catch (\Throwable $e) { }
    }
}
