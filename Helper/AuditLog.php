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

    /**
     * @param \Tagalys\Sync\Model\ResourceModel\AuditLog\Collection
     */
    private $auditLogCollection;

    public function __construct(
        \Magento\Framework\App\ResourceConnection $resourceConnection,
        \Tagalys\Sync\Helper\Configuration $configuration,
        \Tagalys\Sync\Helper\Api $tagalysApi,
        \Tagalys\Sync\Model\ResourceModel\AuditLog\Collection $auditLogCollection
    )
    {
        $this->resourceConnection = $resourceConnection;
        $this->configuration = $configuration;
        $this->tagalysApi = $tagalysApi;
        $this->auditLogCollection = $auditLogCollection;
    }

    public function logInfo($service, $message, $payload = null) {
        $this->log('info', $service, $message, $payload);
    }

    public function syncToTagalys() {
        $logIds = $this->getAllIds();
        Utils::forEachChunk($logIds, 2000, function($idsChunk) {
            $entries = $this->getEntries($idsChunk);
            $response = $this->tagalysApi->clientApiCall('/v1/clients/audit_log', ['log_entries' => $entries]);
            if($response) {
                $this->deleteLogEntries($idsChunk[0], end($idsChunk));
            }
        });
    }

    public function getEntries($ids) {
        $collection = $this->auditLogCollection->clear();
        if($ids != null) {
            $collection->addFieldToFilter('id', ['in' => $ids]);
        }
        return $collection->toArray()['items'];
    }

    public function getAllIds() {
        $rows = $this->resourceConnection->getConnection()->fetchAll("SELECT id FROM {$this->tableName()} ORDER BY id ASC;");
        return array_map(function ($row) {
            return $row['id'];
        }, $rows);
    }

    public function deleteLogEntries($from, $to) {
        $sql = "DELETE FROM {$this->tableName()} WHERE id >= $from AND id <= $to;";
        $this->resourceConnection->getConnection()->query($sql);
    }

    private function log($level, $service, $message, $payload) {
        if($this->configuration->getConfig('fallback:mute_audit_logs', true, true)) {
            return false;
        }
        $data = ['service' => $service, 'message' => $message, 'payload' => $payload, 'timestamp' => Utils::now(), 'level' => $level];
        $dataJson = json_encode($data);
        try {
            $sql = "INSERT INTO {$this->tableName()} (log_data) VALUES ('$dataJson')";
            return $this->resourceConnection->getConnection()->query($sql);
        } catch (\Exception $e) {
            Utils::getLogger('tagalys_audit_logs.log')->err(json_encode([
                'message' => $e->getMessage(),
                'log_data' => $data
            ]));
        }
    }

    private function tableName() {
        if($this->tableName == null) {
            $this->tableName = $this->resourceConnection->getConnection()->getTableName('tagalys_audit_log');
        }
        return $this->tableName;
    }

    public function truncate() {
        $sql = "TRUNCATE {$this->tableName()};";
        $this->resourceConnection->getConnection()->query($sql);
    }
}
