<?php
namespace Tagalys\Sync\Setup;

use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\UpgradeSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

class UpgradeSchema implements UpgradeSchemaInterface
{
    public function upgrade(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();

        if (version_compare($context->getVersion(), '1.1.2', '<')) {
            $setup->getConnection()->addColumn(
                $setup->getTable('tagalys_queue'),
                'priority',
                [
                    'type' => \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER,
                    'nullable' => false,
                    'default' => '0',
                    'comment' => 'Priority of the update'
                ]
            );
        }

        if (version_compare($context->getVersion(), '1.1.3', '<')) {
            $setup->getConnection()->addColumn(
                $setup->getTable('tagalys_queue'),
                'store_id',
                [
                    'type' => Table::TYPE_INTEGER,
                    'nullable' => true,
                    'comment' => 'Store ID'
                ]
            );

            $columnExist = $setup->getConnection()->tableColumnExists(
                $setup->getTable('tagalys_config'),
                'priority'
            );
            if($columnExist) {
                $setup->getConnection()->dropColumn(
                    $setup->getTable('tagalys_config'),
                    'priority'
                );
            }
        }

        if (version_compare($context->getVersion(), '1.1.4', '<')) {
            $setup->getConnection()->addColumn(
                $setup->getTable('tagalys_category'),
                'tagalys_managed_products',
                [
                    'type' => Table::TYPE_BOOLEAN,
                    'nullable' => false,
                    'default' => '0',
                    'comment' => 'Tagalys Managed Products'
                ]
            );
        }

        if (version_compare($context->getVersion(), '1.1.5', '<')) {
            $queueTable = $setup->getConnection()
                ->newTable('tagalys_audit_log')
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
                    'log_data',
                    Table::TYPE_TEXT,
                    null,
                    [
                        'nullable' => false,
                        'default' => ''
                    ],
                    'Log Data'
                )
                ->setComment('Tagalys Audit Log Table')
                ->setOption('type', 'InnoDB')
                ->setOption('charset', 'utf8');
            $setup->getConnection()->createTable($queueTable);
        }

        $setup->endSetup();
    }

}
