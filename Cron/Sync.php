<?php
namespace Tagalys\Sync\Cron;

use Tagalys\Sync\Helper\Utils;

class Sync extends Cron
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
        return "sync";
    }

    protected function perform() {
        $this->tagalysSync->sync();
    }
}
