<?php
namespace Tagalys\Sync\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;

class AssignParentCategoriesToAllProductsViaDb extends Command
{
    public function __construct(
        \Magento\Framework\App\State $appState,
        \Tagalys\Sync\Helper\Category $tagalysCategoryHelper,
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration
    ){
        $this->appState = $appState;
        $this->tagalysCategoryHelper = $tagalysCategoryHelper;
        $this->tagalysConfiguration = $tagalysConfiguration;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('tagalys:assign_parent_categories_to_all_products_via_db');
        $this->setDescription('Assign parent categories to all products via direct DB calls to allow Tagalys to position products');

        parent::configure();
    }
    protected function execute(InputInterface $input, OutputInterface $output)
    {
    }
}
