<?php

namespace Tagalys\Sync\Setup;

use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\DB\Ddl\Table;

class InstallSchema implements InstallSchemaInterface
{
    public function install(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $installer = $setup;
        $installer->startSetup();

        $configTableName = $installer->getTable('tagalys_config');
        if ($installer->getConnection()->isTableExists($configTableName) != true) {
            $configTable = $installer->getConnection()
                ->newTable($configTableName)
                ->addColumn(
                    'id',
                    Table::TYPE_INTEGER,
                    11,
                    [
                        'identity' => true,
                        'unsigned' => true,
                        'nullable' => false,
                        'primary' => true
                    ],
                    'ID'
                )
                ->addColumn(
                    'path',
                    Table::TYPE_TEXT,
                    255,
                    [
                        'nullable' => false,
                        'default' => ''
                    ],
                    'Config Path'
                )
                ->addColumn(
                    'value',
                    Table::TYPE_TEXT,
                    null,
                    [
                        'nullable' => false,
                        'default' => ''
                    ],
                    'Config Value'
                )->addColumn(
                    'priority',
                    Table::TYPE_INTEGER,
                    null,
                    [
                        'nullable' => false,
                        'default' => '0',
                    ],
                    'Priority of the update'
                )
                ->setComment('Tagalys Configuration Table')
                ->setOption('type', 'InnoDB')
                ->setOption('charset', 'utf8');
            $installer->getConnection()->createTable($configTable);
        }

        $queueTableName = $installer->getTable('tagalys_queue');
        if ($installer->getConnection()->isTableExists($queueTableName) != true) {
            $queueTable = $installer->getConnection()
                ->newTable($queueTableName)
                ->addColumn(
                    'id',
                    Table::TYPE_INTEGER,
                    11,
                    [
                        'identity' => true,
                        'unsigned' => true,
                        'nullable' => false,
                        'primary' => true
                    ],
                    'ID'
                )
                ->addColumn(
                    'product_id',
                    Table::TYPE_INTEGER,
                    11,
                    [
                        'nullable' => false,
                        'default' => '0'
                    ],
                    'Product ID'
                )->addIndex(
                    $installer->getIdxName(
                        $queueTableName,
                        ['product_id'],
                        \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE
                    ),
                    ['product_id'],
                    \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE
                )
                ->setComment('Tagalys Sync Queue Table')
                ->setOption('type', 'InnoDB')
                ->setOption('charset', 'utf8');
            $installer->getConnection()->createTable($queueTable);
        }

        $categoryTableName = $installer->getTable('tagalys_category');
        if ($installer->getConnection()->isTableExists($categoryTableName) != true) {
            $categoryTable = $installer->getConnection()
                ->newTable($categoryTableName)
                ->addColumn(
                    'id',
                    Table::TYPE_INTEGER,
                    11,
                    [
                        'identity' => true,
                        'unsigned' => true,
                        'nullable' => false,
                        'primary' => true
                    ],
                    'ID'
                )
                ->addColumn(
                    'category_id',
                    Table::TYPE_INTEGER,
                    50,
                    [
                        'nullable' => false
                    ],
                    'Category ID'
                )
                ->addColumn(
                    'store_id',
                    Table::TYPE_INTEGER,
                    50,
                    [
                        'nullable' => false
                    ],
                    'Store ID'
                )
                ->addColumn(
                    'positions_synced_at',
                    Table::TYPE_DATETIME,
                    null,
                    [],
                    'Positions last synced at'
                )
                ->addColumn(
                    'positions_sync_required',
                    Table::TYPE_BOOLEAN,
                    null,
                    [
                        'nullable' => false,
                        'default' => '0'
                    ],
                    'Positions synced required?'
                )
                ->addColumn(
                    'marked_for_deletion',
                    Table::TYPE_BOOLEAN,
                    null,
                    [
                        'nullable' => false,
                        'default' => '0'
                    ],
                    'Marked for deletion'
                )
                ->addColumn(
                    'status',
                    Table::TYPE_TEXT,
                    255,
                    [
                        'nullable' => false,
                        'default' => ''
                    ],
                    'Status'
                )
                ->setComment('Tagalys Categories Table')
                ->setOption('type', 'InnoDB')
                ->setOption('charset', 'utf8');
            $categoryTable->addIndex(
                $installer->getIdxName('tagalys_category', ['store_id', 'category_id']),
                ['store_id', 'category_id']
            );
            $installer->getConnection()->createTable($categoryTable);
        }

        $installer->endSetup();
    }
}