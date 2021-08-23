<?php
namespace Tagalys\Sync\Helper;

class AuditLog
{
    /**
     * @param \Magento\Framework\App\ResourceConnection
     */
    private $resourceConnection;

    private $tableName = NULL;

    public function __construct(
        \Magento\Framework\App\ResourceConnection $resourceConnection
    )
    {
        $this->resourceConnection = $resourceConnection;
    }

    public function logInfo($message) {
        $this->log('info', $message);
    }

    public function logWarn($message) {
        $this->log('warn', $message);
    }

    public function logError($message) {
        $this->log('error', $message);
    }

    private function log($level, $message) {
        $data = ['level' => $level, 'timestamp' => Utils::now()];
        if(is_a($message, "Array")) {
            $data['message'] = json_encode($message);
        } else {
            $data['message'] = $message;
        }
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
