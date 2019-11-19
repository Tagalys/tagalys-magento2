<?php
namespace Tagalys\Sync\Block;
 
class Productview extends \Magento\Framework\View\Element\Template
{
    public function __construct(
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration,
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Framework\Registry $registry
    )
    {
        $this->tagalysConfiguration = $tagalysConfiguration;
        $this->storeManager = $context->getStoreManager();
        $this->registry = $registry;
        parent::__construct($context);
    }

    public function isTagalysEnabled() {
        return $this->tagalysConfiguration->isTagalysEnabledForStore($this->getCurrentStoreId());
    }

    public function getCurrentStoreId() {
        return $this->storeManager->getStore()->getId();
    }

    public function getMainProduct() {
        $mainProduct = $this->registry->registry('product');
        if (is_object($mainProduct)) {
          return $mainProduct;
        }
        return false;
    }
}