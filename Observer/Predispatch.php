<?php
namespace Tagalys\Sync\Observer;

class Predispatch implements \Magento\Framework\Event\ObserverInterface
{
    /**
     * @param \Magento\Store\Model\StoreManagerInterface
     */
    private $storeManager;

    public function __construct(
        \Magento\Customer\Model\Session $session,
        \Magento\Store\Model\StoreManagerInterface $storeManager
    )
    {
        $this->session = $session;
        $this->storeManager = $storeManager;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        try {
            if($this->session->isLoggedIn()) {
                $customerId = $this->session->getCustomer()->getId();
                // don't use magento's cookie helper as it sets httponly header to true
                setcookie('__ta_user_id', $customerId, time()+60*60*24*3, '/', $_SERVER['HTTP_HOST'], $this->storeManager->getStore()->isFrontUrlSecure());
            } else {
                setcookie("__ta_user_id", "", time()-3600, '/', $_SERVER['HTTP_HOST'], $this->storeManager->getStore()->isFrontUrlSecure());
            }
        } catch (\Throwable $e) {

        }
    }
}
