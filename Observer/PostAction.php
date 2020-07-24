<?php
namespace Tagalys\Sync\Observer;

class PostAction implements \Magento\Framework\Event\ObserverInterface
{
    public function __construct(
        \Magento\Framework\App\RequestInterface $requestInterface,
        \Tagalys\Sync\Helper\Api $tagalysApi,
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration,
        \Tagalys\Sync\Helper\Sync $tagalysSync
    )
    {
        $this->requestInterface = $requestInterface;
        $this->tagalysApi = $tagalysApi;
        $this->tagalysConfiguration = $tagalysConfiguration;
        $this->tagalysSync = $tagalysSync;
    }
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        try {
            $controllerAction = $observer->getEvent()->getControllerAction();
            $params = $controllerAction->getRequest()->getParams();
            $tagalysConfigEvents = array(
                'product_attribute_delete',
                'product_attribute_save',
                'system_currency_saveRates',
                'system_currencysymbol_save'
            );
            $catalogPriceRuleEvents = ['promo_catalog_save', 'promo_catalog_applyRules'];
            $controllerActionName = $this->requestInterface->getControllerName() . '_' . $this->requestInterface->getActionName();

            if (in_array($controllerActionName, $tagalysConfigEvents)) {
                $this->tagalysConfiguration->setConfig("config_sync_required", '1');
            }
            if (in_array($controllerActionName, array("system_config_save"))) {
                $params = $this->requestInterface->getParams();
                if (isset($params['section']) && $params["section"] == "currency") {
                    $this->tagalysConfiguration->setConfig("config_sync_required", '1');
                }
            }
            if (in_array($controllerActionName, array("category_save", "category_delete"))) {
                $stores = $this->tagalysConfiguration->getStoresForTagalys();
                foreach($stores as $i => $storeId) {
                    $this->tagalysConfiguration->setConfig("store:{$storeId}:resync_required", 1);
                }
            }
            if (in_array($controllerActionName, $catalogPriceRuleEvents)) {
                $this->tagalysConfiguration->setConfig('sync:catalog_price_rule_changed', true, true);
            }
        } catch (\Throwable $e) { }
    }
}
