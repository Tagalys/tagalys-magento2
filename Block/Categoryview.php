<?php
namespace Tagalys\Sync\Block;

class Categoryview extends \Magento\Framework\View\Element\Template
{
    private $tagalysConfiguration;
    private $storeManager;
    private $tagalysCategory;
    private $layerResolver;
    
    public function __construct(
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration,
        \Tagalys\Sync\Helper\Category $tagalysCategory,
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Catalog\Model\Layer\Resolver $layerResolver
    )
    {
        $this->tagalysConfiguration = $tagalysConfiguration;
        $this->storeManager = $context->getStoreManager();
        $this->tagalysCategory = $tagalysCategory;
        $this->layerResolver = $layerResolver;
        parent::__construct($context);
    }

    public function isTagalysEnabled() {
        return $this->tagalysConfiguration->isTagalysEnabledForStore($this->getCurrentStoreId());
    }

    public function getCurrentStoreId() {
        return $this->storeManager->getStore()->getId();
    }

    public function getCurrentCategory() {
        $category = $this->layerResolver->get()->getCurrentCategory();
        return $category;
    }

    public function isTagalysCreated(){
        $category = $this->layerResolver->get()->getCurrentCategory();
        return $this->tagalysCategory->isTagalysCreated($category->getId());
    }

    public function getUseLegacyJavaScript() {
        return $this->tagalysConfiguration->getConfig('useLegacyJavaScript');
    }

    public function isCategoryRenderedByTagalys() {
        $category = $this->getCurrentCategory();
        return $this->tagalysConfiguration->isJsRenderingEnabledForCategory($this->getCurrentStoreId(), $category);
    }
}
