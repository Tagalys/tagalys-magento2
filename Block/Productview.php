<?php
namespace Tagalys\Sync\Block;

class Productview extends \Magento\Framework\View\Element\Template
{
    public function __construct(
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration,
        \Tagalys\Sync\Helper\Product $tagalysProductHelper,
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Framework\Registry $registry
    )
    {
        $this->tagalysConfiguration = $tagalysConfiguration;
        $this->tagalysProductHelper = $tagalysProductHelper;
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

    public function getEventDetails() {
        // FIXME: registry is deprecated
        $product = $this->registry->registry('product');
        if (is_object($product)) {
            $eventDetails = ['action' => 'view'];
            if($product->getTypeId() == 'configurable' && $this->tagalysConfiguration->areChildSimpleProductsVisibleIndividually()) {
                $visibleChildren = $this->tagalysProductHelper->getVisibleChildren($product);
                if(count($visibleChildren) > 0) {
                    $eventDetails['skus'] = [];
                    foreach($visibleChildren as $visibleChild) {
                        $eventDetails['skus'][] = $visibleChild->getSku();
                    }
                    return $eventDetails;
                }
            }
            $eventDetails['sku'] = $product->getSku();
            return $eventDetails;
        }
        return false;
    }
}
