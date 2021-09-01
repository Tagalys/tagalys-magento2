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

    /**
     * @param \Tagalys\Sync\Helper\Api
     */
    private $tagalysApi;

    public function __construct(
        \Magento\Framework\App\State $appState,
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration,
        \Tagalys\Sync\Helper\Api $tagalysApi
    ) {
        $this->appState = $appState;
        $this->tagalysConfiguration = $tagalysConfiguration;
        $this->tagalysApi = $tagalysApi;
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

    public function tryExecute($calledThroughMagentoCron = true) {
        try {
            $magentoCronEnabled = $this->tagalysConfiguration->getConfig("magento_cron_enabled", true, true);
            $canRun = (($calledThroughMagentoCron && $magentoCronEnabled) || (!$calledThroughMagentoCron && !$magentoCronEnabled));
            if ($canRun) {
                $this->beforeEach($calledThroughMagentoCron);
                $this->perform();
                return true;
            }
            return false;
        } catch (\Throwable $e) {
            $this->tagalysApi->logExceptionToTagalys($e, "Exception in cron execution");
        }
    }
}
