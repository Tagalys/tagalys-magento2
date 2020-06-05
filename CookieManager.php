<?php 

namespace Tagalys\Sync;

class CookieManager
{
    /**
     * CookieManager
     *
     * @var CookieManagerInterface
     */
    private $cookieManager;

    /**
     * @var CookieMetadataFactory
     */
    private $cookieMetadataFactory;

    /**
     * @var SessionManagerInterface
     */
    private $sessionManager;

    /**
     * @param CookieManagerInterface $cookieManager
     * @param CookieMetadataFactory $cookieMetadataFactory
     * @param SessionManagerInterface $sessionManager
     */
    public function __construct(
        \Magento\Framework\Stdlib\CookieManagerInterface $cookieManager,
        \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory $cookieMetadataFactory,
        \Magento\Framework\Session\SessionManagerInterface $sessionManager
    ) {
        $this->cookieManager = $cookieManager;
        $this->cookieMetadataFactory = $cookieMetadataFactory;
        $this->sessionManager = $sessionManager;
    }

    /**
     * Get form key cookie
     *
     * @return string
     */
    public function get($cookieName)
    {
        return $this->cookieManager->getCookie($cookieName);
    }

    /**
     * @param string $value
     * @param int $duration
     * @return void
     */
    public function set($cookieName, $value, $duration = 86400)
    {
        $metadata = $this->cookieMetadataFactory
          ->createPublicCookieMetadata()
          ->setDuration($duration)
          ->setPath('/')
          ->setDomain($this->sessionManager->getCookieDomain());

        $this->cookieManager->setPublicCookie(
            $cookieName,
            $value,
            $metadata
        );
    }

    /**
     * @return void
     */
    public function delete($cookieName)
    {
        $this->cookieManager->deleteCookie(
            $cookieName,
            $this->cookieMetadataFactory
                ->createCookieMetadata()
                ->setPath('/')
                ->setDomain($this->sessionManager->getCookieDomain())
        );
    }
}
