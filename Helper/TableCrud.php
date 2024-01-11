<?php
namespace Tagalys\Sync\Helper;

class TableCrud
{
    /**
     * @param \Magento\Framework\App\ResourceConnection
     */
    private $resourceConnection;
    private $writableTables;

    public function __construct(
        \Magento\Framework\App\ResourceConnection $resourceConnection
    )
    {
        $this->resourceConnection = $resourceConnection;

        $this->writableTables = [];
    }


    public function select($tableName, $where = null, $order = null, $limit = null, $offset = null) {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()->from($tableName);
        if ($where) {
            $select->where($where[0], $where[1]);
        }
        if ($limit) {
            $select->limit($limit, $offset);
        }
        if ($order) {
            $select->order($order);
        }
        return $connection->fetchAll($select);
    }
}
