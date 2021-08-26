<?php
namespace Tagalys\Sync\Cron;

class RunMaintenance extends Cron
{
    /**
     * @param \Tagalys\Sync\Helper\Sync
     */
    private $tagalysSync;

    public function __construct(
        \Magento\Framework\App\State $appState,
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration,
        \Tagalys\Sync\Helper\Sync $tagalysSync,
        \Tagalys\Sync\Helper\Api $tagalysApi
    ) {
        parent::__construct($appState, $tagalysConfiguration, $tagalysApi);
        $this->tagalysSync = $tagalysSync;
    }

    protected function heartbeatName() {
        return "run_maintenance";
    }

    protected function perform() {
        $this->tagalysSync->runMaintenance();
    }
}
