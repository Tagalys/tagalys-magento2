<?php
namespace Tagalys\Sync\Block;
 
class Tagalys extends \Magento\Framework\View\Element\Template
{

    private $tagalysConfiguration;
    private $storeManager;

    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration
    )
    {
        $this->tagalysConfiguration = $tagalysConfiguration;
        $this->storeManager = $context->getStoreManager();
        parent::__construct($context);
    }

    public function isTagalysEnabled($module = false) {
        return $this->tagalysConfiguration->isTagalysEnabledForStore($this->getCurrentStoreId(), $module);
    }

    public function apiCredentials() {
        return $this->tagalysConfiguration->getConfig('api_credentials', true);
    }

    public function getCurrentCurrency() {
        try {
            return $this->tagalysConfiguration->getCurrenciesByCode($this->storeManager->getStore())[$this->storeManager->getStore()->getCurrentCurrency()->getCode()];
        } catch (\Exception $e) {
            return $this->tagalysConfiguration->getCurrencies($this->storeManager->getStore(), true);
        }
    }

    public function getCurrentStoreId() {
        return $this->storeManager->getStore()->getId();
    }

    public function getBaseUrl() {
      return $this->storeManager->getStore()->getBaseUrl();
    }

    public function getTsearchUrl() {
      return $this->getBaseUrl() . 'tsearch';
    }

    public function getTagalysConfig($path, $json_decode = false) {
      return $this->tagalysConfiguration->getConfig($path, $json_decode);
    }
}