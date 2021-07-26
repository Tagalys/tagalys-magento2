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
                Table::TYPE_INTEGER,
                null,
                [
                    'nullable' => false,
                ],
                'Store ID'
            );
            $columnExist = $setup->getConnection()->tableColumnExists(
                $setup->getTable('tagalys_config'),
                'priority',
            );
            if($columnExist) {
                // TODO: Test the case when the colum does not exist
                $setup->getConnection()->dropColumn(
                    $setup->getTable('tagalys_config'),
                    'priority',
                );
            }
        }

        $setup->endSetup();
    }

}
