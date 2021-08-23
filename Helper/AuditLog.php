<?php
namespace Tagalys\Sync\Helper;

class AuditLog
{
    /**
     * @param \Magento\Framework\App\ResourceConnection
     */
    private $resourceConnection;

    private $tableName = NULL;

    /**
     * @param \Tagalys\Sync\Helper\Configuration
     */
    private $configuration;

    public function __construct(
        \Magento\Framework\App\ResourceConnection $resourceConnection,
        \Tagalys\Sync\Helper\Configuration $configuration
    )
    {
        $this->resourceConnection = $resourceConnection;
        $this->configuration = $configuration;
    }

    public function logInfo($service, $message, $payload = null) {
        $this->log('info', $service, $message, $payload);
    }

    private function log($level, $service, $message, $payload) {
        if($this->configuration->getConfig('fallback:mute_audit_logs', true, true)) {
            return false;
        }
        $data = ['service' => $service, 'message' => $message, 'payload' => $payload, 'timestamp' => Utils::now(), 'level' => $level];
        $dataJson = json_encode($data);
        $sql = "INSERT INTO {$this->tableName()} (log_data) VALUES ('$dataJson')";
        return $this->resourceConnection->getConnection()->query($sql);
    }

    private function tableName() {
        if($this->tableName == null) {
            $this->tableName = $this->resourceConnection->getConnection()->getTableName('tagalys_audit_log');
        }
        return $this->tableName;
    }
}
