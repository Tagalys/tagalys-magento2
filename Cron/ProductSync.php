<?php
namespace Tagalys\Sync\Cron;

class ProductSync extends Cron
{
    /**
     * @param \Tagalys\Sync\Helper\Sync
     */
    private $tagalysSync;

    public function __construct(
        \Magento\Framework\App\State $appState,
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration,
        \Tagalys\Sync\Helper\Sync $tagalysSync
    ) {
        parent::__construct($appState, $tagalysConfiguration);
        $this->tagalysSync = $tagalysSync;
    }

    protected function heartbeatName() {
        return "product_sync";
    }

    protected function perform() {
        $this->tagalysSync->sync();
    }
}
