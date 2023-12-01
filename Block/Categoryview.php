<?php
namespace Tagalys\Sync\Block;
 
class Categoryview extends \Magento\Framework\View\Element\Template
{
    private $tagalysConfiguration;
    private $storeManager;
    private $registry;
    private $tagalysCategory;
    private $_category;
    private $pageTitle;
    
    public function __construct(
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration,
        \Tagalys\Sync\Helper\Category $tagalysCategory,
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\View\Page\Title $pageTitle
    )
    {
        $this->tagalysConfiguration = $tagalysConfiguration;
        $this->storeManager = $context->getStoreManager();
        $this->registry = $registry;
        $this->tagalysCategory = $tagalysCategory;
        $this->_category = $this->registry->registry('current_category');
        $this->pageTitle = $pageTitle;
        parent::__construct($context);
    }

    public function isTagalysEnabled() {
        return $this->tagalysConfiguration->isTagalysEnabledForStore($this->getCurrentStoreId());
    }

    public function getCurrentStoreId() {
        return $this->storeManager->getStore()->getId();
    }

    public function getCurrentCategory() {
        return $this->_category;
    }

    public function isTagalysCreated(){
        return $this->tagalysCategory->isTagalysCreated($this->_category->getId());
    }
}