<?php
namespace Tagalys\Sync\Cron;

class PositionUpdate extends Cron
{
    /**
     * @param \Tagalys\Sync\Helper\Category
     */
    private $tagalysCategory;

    public function __construct(
        \Magento\Framework\App\State $appState,
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration,
        \Tagalys\Sync\Helper\Category $tagalysCategory,
        \Tagalys\Sync\Helper\Api $tagalysApi
    ) {
        $this->tagalysCategory = $tagalysCategory;
        parent::__construct($appState, $tagalysConfiguration, $tagalysApi);
    }

    protected function heartbeatName() {
        return "update_product_positions";
    }

    protected function perform() {
        $this->tagalysCategory->updatePositionsIfRequired();
    }
}
