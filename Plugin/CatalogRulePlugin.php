<?php
namespace Tagalys\Sync\Plugin;

class CatalogRulePlugin {

  private $tagalysSync;
  private $tagalysConfiguration;
  private $tagalysQueue;

  public function __construct(
    \Tagalys\Sync\Helper\Sync $tagalysSync,
    \Tagalys\Sync\Helper\Configuration $tagalysConfiguration,
    \Tagalys\Sync\Helper\Queue $tagalysQueue
  ) {
    $this->tagalysSync = $tagalysSync;
    $this->tagalysConfiguration = $tagalysConfiguration;
    $this->tagalysQueue = $tagalysQueue;
  }

  public function afterReindexFull() {
    try{
      $catalogRuleChanged = $this->tagalysConfiguration->getConfig('sync:catalog_price_rule_changed', true);
      if($catalogRuleChanged){
        $triggerQuickFeed = $this->tagalysConfiguration->getConfig('sync:trigger_quick_feed_for_catalog_price_rule_change', true);
        if($triggerQuickFeed) {
            $this->tagalysSync->triggerQuickFeed();
        } else {
            $this->tagalysSync->triggerFullSync();
        }
        $this->tagalysConfiguration->setConfig('sync:catalog_price_rule_changed',false, true);
      }
    } catch (\Exception $e) {}
  }

  public function afterReindexByIds($subject, $result, $ids) {
    $recordSpecificProductRuleApply = $this->tagalysConfiguration->getConfig('sync:record_price_rule_updates_for_each_product', true);
    if($recordSpecificProductRuleApply){
      $this->tagalysQueue->insertUnique($ids);
    }
    try{
    } catch (\Exception $e) {}
  }
}