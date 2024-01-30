<?php
namespace Tagalys\Sync\Block;

use Tagalys\Sync\Helper\Utils;

class Tagalys extends \Magento\Framework\View\Element\Template
{

    private $tagalysConfiguration;

    /**
     * @var Magento\Framework\UrlInterface
     */
    private $urlInterface;

    /**
     * @var \Magento\Framework\Url\EncoderInterface
     */
    private $urlEncoderInterface;

    /**
     * @var \Magento\Framework\Data\Form\FormKey
     */
    private $formKey;

    private $storeManager;

    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration,
        \Magento\Framework\UrlInterface $urlInterface,
        \Magento\Framework\Url\EncoderInterface $urlEncoderInterface,
        \Magento\Framework\Data\Form\FormKey $formKey
    )
    {
        $this->tagalysConfiguration = $tagalysConfiguration;
        $this->urlInterface = $urlInterface;
        $this->urlEncoderInterface = $urlEncoderInterface;
        $this->formKey = $formKey;
        $this->storeManager = $context->getStoreManager();
        parent::__construct($context);
    }

    public function isTagalysEnabled($module = false) {
        $jsFileUrl = $this->tagalysConfiguration->getTagalysJsUrl();
        if (empty($jsFileUrl)) {
            return false;
        }
        $isTagalysHealthy = $this->tagalysConfiguration->isTagalysHealthy();
        if(!$isTagalysHealthy) {
            return false;
        }
        $enabled = $this->tagalysConfiguration->isTagalysEnabledForStore($this->getCurrentStoreId(), $module);
        return $enabled;
    }

    public function getTagalysJsUrl() {
        return $this->tagalysConfiguration->getTagalysJsUrl();
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

    public function getUseLegacyJavaScript() {
        return $this->tagalysConfiguration->getConfig('useLegacyJavaScript');
    }
}

    public function getUenc() {
        return $this->urlEncoderInterface->encode($this->urlInterface->getCurrentUrl());
    }

    public function getFormKey() {
        return $this->formKey->getFormKey();
    }
}
