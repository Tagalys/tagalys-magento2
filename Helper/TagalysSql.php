<?php
namespace Tagalys\Sync\Helper;

class TagalysSql
{
    /**
     * @param \Magento\Framework\App\ResourceConnection
     */
    private $resourceConnection;

    public function __construct(
        \Magento\Framework\App\ResourceConnection $resourceConnection
    )
    {
        $this->resourceConnection = $resourceConnection;
    }

    // Not for SELECT
    public function runSql($sql) {
        $conn = $this->resourceConnection->getConnection();
        $conn->query("SET time_zone = '+00:00'; $sql");
    }

    public function runSqlSelect($sql) {
        $conn = $this->resourceConnection->getConnection();
        $conn->query("SET time_zone = '+00:00'");
        return $conn->fetchAll($sql);
    }

    public function getTableName($name){
        return $this->resourceConnection->getTableName($name);
    }
}
