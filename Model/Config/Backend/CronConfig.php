<?php
namespace Tagalys\Sync\Model\Config\Backend;

use Tagalys\Sync\Helper\Utils;

class CronConfig extends \Magento\Framework\App\Config\Value
{
    const MAINTENANCE_CRON_EXP_PATH = 'tagalys_cron/maintenance/cron_expr';

    const SYNC_CRON_EXP_PATH = 'tagalys_cron/sync/cron_expr';

    const POSITION_UPDATE_CRON_EXP_PATH = 'tagalys_cron/position_update/cron_expr';

    /**
     * @var \Magento\Framework\App\Config\ValueFactory
     */
    protected $_configValueFactory;

    /**
     * @param \Tagalys\Sync\Helper\Configuration
     */
    private $tagalysConfiguration;

    /**
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $config
     * @param \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList
     * @param \Magento\Framework\App\Config\ValueFactory $configValueFactory
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb $resourceCollection
     * @param string $runModelPath
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\App\Config\ScopeConfigInterface $config,
        \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList,
        \Magento\Framework\App\Config\ValueFactory $configValueFactory,
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->_configValueFactory = $configValueFactory;
        $this->tagalysConfiguration = $tagalysConfiguration;
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * {@inheritdoc}
     *
     * @return $this
     * @throws \Exception
     */
    public function afterSave()
    {
        if ($this->tagalysConfiguration->getConfig("magento_cron_enabled", true, true)) {
            $this->updateMaintenanceCronExp();
            $this->updateSyncCronExp();
            $this->updatePositionUpdateCronExp();
        }

        return parent::afterSave();
    }

    private function updateMaintenanceCronExp() {
        $time = $this->getData('groups/maintenance/fields/start_time/value');
        $frequency = $this->getData('groups/maintenance/fields/frequency/value');

        $cronExprArray = [
            intval($time[1]), //Minute
            intval($time[0]), //Hour
            '*', //Day of the Month
            '*', //Month of the Year
            $frequency, //Day of the Week
        ];

        $cronExprString = join(' ', $cronExprArray);

        $this->updateConfig(self::MAINTENANCE_CRON_EXP_PATH, $cronExprString);
    }

    private function updateSyncCronExp() {
        $frequency = $this->getData('groups/sync/fields/frequency/value');
        $cronExprString = "$frequency * * * *";
        $this->updateConfig(self::SYNC_CRON_EXP_PATH, $cronExprString);
    }

    private function updatePositionUpdateCronExp() {
        $frequency = $this->getData('groups/position_update/fields/frequency/value');
        $cronExprString = "$frequency * * * *";
        $this->updateConfig(self::POSITION_UPDATE_CRON_EXP_PATH, $cronExprString);
    }

    private function updateConfig($path, $value) {
        try {
            $this->_configValueFactory->create()->load(
                $path,
                'path'
            )->setValue(
                $value
            )->setPath(
                $path
            )->save();
        } catch (\Exception $e) {
            throw new \Exception(__('We couldn\'t save the maintenance cron expression.'));
        }
    }
}