<?php
namespace Tagalys\Sync\Helper;

class TableCrud
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

        $this->writableTables = [];
    }


    public function select($tableName, $order = null, $limit = null, $offset = null) {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()->from($tableName);
        if ($limit) {
            $select->limit($limit, $offset);
        }
        if ($order) {
            $select->order($order);
        }
        return $connection->fetchAll($select);
    }
}
