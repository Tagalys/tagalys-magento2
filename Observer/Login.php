<?php
namespace Tagalys\Sync\Observer;

class Login implements \Magento\Framework\Event\ObserverInterface
{
    public function __construct(
        \Magento\Customer\Model\Session $session
    )
    {
        $this->session = $session;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        try {
            $customerId = $observer->getCustomer()->getId();
            // don't use magento's cookie helper as it sets httponly header to true
            setcookie('__ta_logged_in', $customerId, time()+60*60*24*3, '/', $_SERVER['HTTP_HOST']);
        } catch (\Throwable $e) {

        }
    }
}
