<?php
namespace Tagalys\Sync\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;

class TriggerFullSync extends Command
{
    public function __construct(
        \Magento\Framework\App\State $appState,
        \Tagalys\Sync\Helper\Sync $syncHelper,
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration
    ) {
        $this->appState = $appState;
        $this->syncHelper = $syncHelper;
        $this->tagalysConfiguration = $tagalysConfiguration;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('tagalys:trigger_full_sync');
        $this->setDescription('Trigger full products sync to Tagalys');

        parent::configure();
    }
    protected function execute(InputInterface $input, OutputInterface $output)
    {
    }
}
