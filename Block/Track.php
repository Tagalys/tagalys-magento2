<?php
namespace Tagalys\Sync\Block;

use Tagalys\Sync\Helper\Utils;
 
class Track extends \Magento\Framework\View\Element\Template
{
    private $tagalysConfiguration;
    private $storeManager;
    private $cookieManager;
    private $frontUrlHelper;
    private $logger;
    
    public function __construct(
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration,
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Framework\Url $frontUrlHelper,
        \Tagalys\Sync\CookieManager $cookieManager
    )
    {
        $this->tagalysConfiguration = $tagalysConfiguration;
        $this->storeManager = $context->getStoreManager();
        $this->cookieManager = $cookieManager;
        $this->frontUrlHelper = $frontUrlHelper;
        $this->logger = Utils::getLogger("tagalys_custom.log");
        parent::__construct($context);
    }

    public function isTagalysEnabled() {
        return $this->tagalysConfiguration->isTagalysEnabledForStore($this->getCurrentStoreId());
    }

    public function getCurrentStoreId() {
        return $this->storeManager->getStore()->getId();
    }

    public function getProductDetailsUrl() {
        return $this->frontUrlHelper->getUrl('tanalytics/product/details/');
    }

    public function getUseLegacyJavaScript() {
        return $this->tagalysConfiguration->getConfig('useLegacyJavaScript');
    }
}