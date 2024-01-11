<?php
namespace Tagalys\Sync\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;

class RunMaintenance extends Command
{
    /**
     * @param \Tagalys\Sync\Cron\RunMaintenance
     */
    private $runMaintenanceCron;

    public function __construct(
        \Tagalys\Sync\Cron\RunMaintenance $runMaintenanceCron
    ) {
        $this->runMaintenanceCron = $runMaintenanceCron;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('tagalys:run_maintenance');
        $this->setDescription('Run Tagalys maintenance tasks');

        parent::configure();
    }
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->runMaintenanceCron->tryExecute(false);
        $output->writeln("Done");

        return 1;
    }
}
