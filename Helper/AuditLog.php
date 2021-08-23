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

    /**
     * @param \Tagalys\Sync\Helper\Api
     */
    private $tagalysApi;

    public function __construct(
        \Magento\Framework\App\ResourceConnection $resourceConnection,
        \Tagalys\Sync\Helper\Configuration $configuration,
        \Tagalys\Sync\Helper\Api $tagalysApi
    )
    {
        $this->resourceConnection = $resourceConnection;
        $this->configuration = $configuration;
        $this->tagalysApi = $tagalysApi;
    }

    public function logInfo($service, $message, $payload = null) {
        $this->log('info', $service, $message, $payload);
    }

    public function syncToTagalys() {
        $logEntries = $this->getAllEntries();
        Utils::forEachChunk($logEntries, 2000, function($chunk) {
            $response = $this->tagalysApi->clientApiCall('/v1/clients/audit_log', ['log_entries' => $chunk]);
            if($response) {
                $this->deleteLogEntries($chunk[0]['id'], end($chunk)['id']);
            }
        });
    }

    public function getAllEntries() {
        return $this->resourceConnection->getConnection()->fetchAll("SELECT * FROM {$this->tableName()} ORDER BY id ASC;");
    }

    private function deleteLogEntries($from, $to) {
        $sql = "DELETE FROM {$this->tableName()} WHERE id >= $from AND id <= $to;";
        $this->resourceConnection->getConnection()->query($sql);
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
