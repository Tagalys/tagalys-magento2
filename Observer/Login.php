<?php
namespace Tagalys\Sync\Observer;

class Login implements \Magento\Framework\Event\ObserverInterface
{
    private $cookieManager;

    public function __construct(
        \Tagalys\Sync\CookieManager $cookieManager
    )
    {
        $this->cookieManager = $cookieManager;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        try {
            $customerId = $observer->getCustomer()->getId();
            $this->cookieManager->set('__ta_logged_in', $customerId);
        } catch (\Throwable $e) {

        }
    }
}
