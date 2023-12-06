<?php
namespace Tagalys\Sync\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;

class UpdateProductPositions extends Command
{
    const MAX_PRODUCTS = 'max_products';
    const MAX_CATEGORIES = 'max_categories';

    /**
     * @param \Tagalys\Sync\Cron\PositionUpdate
     */
    private $positionUpdateCron;

    public function __construct(
        \Tagalys\Sync\Cron\PositionUpdate $positionUpdateCron

    ){
        $this->positionUpdateCron = $positionUpdateCron;
        parent::__construct();
    }

    protected function configure()
    {
        $options = [
            new InputOption(
                self::MAX_CATEGORIES,
                null,
                InputOption::VALUE_REQUIRED,
                '50'
            )
        ];
        $this->setName('tagalys:update_product_positions');
        $this->setDescription('Update category product positions from Tagalys');
        $this->setDefinition($options);

        parent::configure();
    }
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->positionUpdateCron->tryExecute(false);
        $output->writeln("Done");

        return 1;
    }
}
