<?php
namespace Tagalys\Sync\Block;

class Productview extends \Magento\Framework\View\Element\Template
{

    private $tagalysConfiguration;
    private $tagalysProductHelper;
    private $storeManager;
    private $catalogHelper;

    public function __construct(
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration,
        \Tagalys\Sync\Helper\Product $tagalysProductHelper,
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Catalog\Helper\Data $catalogHelper
    )
    {
        $this->tagalysConfiguration = $tagalysConfiguration;
        $this->tagalysProductHelper = $tagalysProductHelper;
        $this->storeManager = $context->getStoreManager();
        $this->catalogHelper = $catalogHelper;
        parent::__construct($context);
    }

    public function isTagalysEnabled() {
        return $this->tagalysConfiguration->isTagalysEnabledForStore($this->getCurrentStoreId());
    }

    public function getCurrentStoreId() {
        return $this->storeManager->getStore()->getId();
    }

    public function getProductIdentifier() {
        $product = $this->catalogHelper->getProduct();
        if (is_object($product)) {
            return $product->getSku();
        }
        else {
            return false;
        }
    }

    public function getUseLegacyJavaScript() {
        return $this->tagalysConfiguration->getConfig('useLegacyJavaScript');
    }

    public function getEventDetails() {
        $product = $this->catalogHelper->getProduct();
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
