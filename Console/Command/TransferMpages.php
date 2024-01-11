<?php
namespace Tagalys\Sync\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

class TransferMpages extends Command
{
    const KEEP_OLD_URL = 'keep_old_url';
    const STORES = 'stores';

    private $appState;
    private $tagalysConfiguration;
    private $tagalysSync;
    private $tagalysCategory;
    private $tagalysApi;
    private $storeManagerInterface;
    
    public function __construct(
        \Magento\Framework\App\State $appState,
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration,
        \Tagalys\Sync\Helper\Sync $tagalysSync,
        \Tagalys\Sync\Helper\Category $tagalysCategory,
        \Tagalys\Sync\Helper\Api $tagalysApi,
        \Magento\Store\Model\StoreManagerInterface $storeManagerInterface
    ){
        $this->appState = $appState;
        $this->tagalysConfiguration = $tagalysConfiguration;
        $this->tagalysSync = $tagalysSync;
        $this->tagalysCategory = $tagalysCategory;
        $this->tagalysApi = $tagalysApi;
        $this->storeManagerInterface = $storeManagerInterface;
        parent::__construct();
    }
    
    protected function configure()
    {
        $options = [
            new InputOption(
                self::KEEP_OLD_URL,
                null,
                InputOption::VALUE_REQUIRED,
                '1'
            ),
            new InputOption(
                self::STORES,
                null,
                InputOption::VALUE_REQUIRED
            )
        ];
        $this->setName('tagalys:transfer_mpages');
        $this->setDescription('Transfers Tagalys Mpages to magento as Smart-Pages');
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
        
        try{
            $keepOldUrl = $input->getOption(self::KEEP_OLD_URL);
            $storeIds = $input->getOption(self::STORES);
            if($storeIds == null || !in_array($keepOldUrl, ['0','1'])){
                throw new \Exception('ERROR: 2 params required. Eg. --stores 1,2,3... --keep_old_url 0 or 1');
            }
            $storeIds = explode(',',$input->getOption(self::STORES));
            foreach($storeIds as $storeId){
                $output->writeln("Fetching mpages from Tagalys");
                $response = $this->tagalysApi->clientApiCall('/v1/mpages/get_store_mpages', ['store_id'=>$storeId]);
                if($keepOldUrl === '1'){
                    $output->writeln("Creating Legacy Tagalys Category");
                    $rootCategoryId = $this->storeManagerInterface->getStore($storeId)->getRootCategoryId();
                    $legacyCategories = $this->tagalysConfiguration->getConfig('legacy_mpage_categories', true);
                    $legacyCategory = $this->categoryCollection->setStoreId($storeId)->addAttributeToSelect('entity_id')->addAttributeToFilter('entity_id', ['in' => $legacyCategories])->getFirstItem();
                    if(!$legacyCategory->getId()){
                        $legacyRootCategoryId = $this->tagalysCategory->_createCategory($rootCategoryId, ['name'=>'Tagalys Legacy Pages', 'url_key'=>'m', 'is_active'=>false]);
                        $legacyCategories[] = $legacyRootCategoryId;
                        $this->tagalysConfiguration->setConfig('legacy_mpage_categories', $legacyCategories, true);
                    } else {
                        $legacyRootCategoryId = $legacyCategory->getId();
                    }
                }
                foreach ($response['mpages'] as $mpage) {
                    $output->writeln("Transferring Mpage: {$mpage['details']['name']}");
                    if($keepOldUrl === '1'){
                        $categoryId = $this->tagalysCategory->_createCategory($legacyRootCategoryId, $mpage['details']);
                        $this->tagalysCategory->updateCategoryUrlRewrite($storeId, $categoryId, 'm/'.$mpage['details']['url_key']);
                    } else {
                        $categoryId = $this->tagalysCategory->createCategory($storeId, $mpage['details']);
                        $this->tagalysCategory->redirectToCategoryUrl($storeId, $categoryId, 'm/'.$mpage['details']['url_key']);
                    }
                    $res = $this->tagalysApi->clientApiCall('/v1/mpages/set_platform_id', ['platform_id'=>$categoryId, 'mpage_id'=>$mpage['id']]);
                    if(!$res){
                        throw new \Exception("Error while sending categoryId $categoryId for mpage {$mpage['es_id']} to Tagalys.");
                    }
                }
            }
        } catch(\Exception $e){
            $output->writeln('ERROR: '.$e->getMessage());
        }

        $output->writeln("Done");

        return 1;
    }
}