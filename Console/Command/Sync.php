<?php
namespace Tagalys\Sync\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;

class Sync extends Command
{
    const MAX_PRODUCTS = 'max_products';
    const MAX_CATEGORIES = 'max_categories';

    /**
     * @param \Tagalys\Sync\Cron\ProductSync
     */
    private $syncCron;

    /**
     * @param \Tagalys\Sync\Cron\CategorySync
     */
    private $categorySync;

    public function __construct(
        \Tagalys\Sync\Cron\ProductSync $syncCron,
        \Tagalys\Sync\Cron\CategorySync $categorySync
    ){
        $this->syncCron = $syncCron;
        $this->categorySync = $categorySync;
        parent::__construct();
    }

    protected function configure()
    {
        $options = [
            new InputOption(
                self::MAX_PRODUCTS,
                null,
                InputOption::VALUE_REQUIRED,
                '500'
            ),
            new InputOption(
                self::MAX_CATEGORIES,
                null,
                InputOption::VALUE_REQUIRED,
                '50'
            )
        ];
        $this->setName('tagalys:sync');
        $this->setDescription('Sync products and categories to Tagalys');
        $this->setDefinition($options);

        parent::configure();
    }
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->categorySync->tryExecute(false);
        $this->syncCron->tryExecute(false);
        $output->writeln("Done");

        return 1;
    }
}
