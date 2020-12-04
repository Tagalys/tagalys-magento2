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
    
    public function __construct(
        \Magento\Framework\App\State $appState,
        \Tagalys\Sync\Helper\Sync $syncHelper,
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration
    ){
        $this->appState = $appState;
        $this->syncHelper = $syncHelper;
        $this->tagalysConfiguration = $tagalysConfiguration;
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
        try {
            $this->appState->setAreaCode('adminhtml');
        } catch (\Magento\Framework\Exception\LocalizedException $exception) {
            // do nothing
        }
        $utcNow = new \DateTime("now", new \DateTimeZone('UTC'));
        $timeNow = $utcNow->format(\DateTime::ATOM);
        $this->tagalysConfiguration->setConfig('heartbeat:command:sync', $timeNow);
        
        $maxP = $input->getOption(self::MAX_PRODUCTS);
        $maxC = $input->getOption(self::MAX_CATEGORIES);

        $this->syncHelper->sync(intval($maxC));

        $output->writeln("Done");
    }
}