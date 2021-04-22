<?php
namespace Tagalys\Sync\Cron;

use Tagalys\Sync\Helper\Utils;

abstract class Cron
{
    protected $logger;

    /**
     * @param \Magento\Framework\App\State
     */
    private $appState;

    /**
     * @param \Tagalys\Sync\Helper\Configuration
     */
    private $tagalysConfiguration;

    public function __construct(
        \Magento\Framework\App\State $appState,
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration
    ) {
        $this->appState = $appState;
        $this->tagalysConfiguration = $tagalysConfiguration;
        $this->logger = Utils::getLogger("tagalys_cron.log");
    }

    protected abstract function heartbeatName();

    protected abstract function perform();

    private function beforeEach($calledThroughMagentoCron) {
        try {
            $this->appState->setAreaCode('adminhtml');
        } catch (\Magento\Framework\Exception\LocalizedException $ignored) {
        }
        $now = Utils::now();
        $name = $this->heartbeatName();
        $this->tagalysConfiguration->setConfig("heartbeat:command:$name", $now);
        if($calledThroughMagentoCron){
            $this->tagalysConfiguration->setConfig("heartbeat:magento_cron:$name", $now);
        } else {
            $this->tagalysConfiguration->setConfig("heartbeat:system_cron:$name", $now);
        }
    }

    public function execute() {
        $this->beforeEach(false);
        $this->perform();
    }

    // Called through Magento cron
    public function tryExecute() {
        if ($this->tagalysConfiguration->getConfig("magento_cron_enabled", true, true)) {
            $this->beforeEach(true);
            $this->perform();
        }
    }
}
