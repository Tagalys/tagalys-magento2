<?php
namespace Tagalys\Sync\Block;
 
class Track extends \Magento\Framework\View\Element\Template
{
    public function __construct(
        \Tagalys\Sync\CookieManager $cookieManager,
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration,
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Framework\Url $frontUrlHelper,
        \Magento\Framework\Registry $registry
    )
    {
        $this->cookieManager = $cookieManager;
        $this->tagalysConfiguration = $tagalysConfiguration;
        $this->storeManager = $context->getStoreManager();
        $this->registry = $registry;
        $this->frontUrlHelper = $frontUrlHelper;
        parent::__construct($context);
    }

    public function isTagalysEnabled() {
        return $this->tagalysConfiguration->isTagalysEnabledForStore($this->getCurrentStoreId());
    }

    public function getCurrentStoreId() {
        return $this->storeManager->getStore()->getId();
    }

    public function getEvent() {
        try {
            $event = $this->registry->registry('tagalys_analytics_event');
            if ($event != false) {
                $event = json_decode($event, true);
            }
            return $event;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getProductDetailsUrl() {
        return $this->frontUrlHelper->getUrl('tanalytics/product/details/');
    }
}