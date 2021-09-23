<?php
    namespace Tagalys\Sync\Controller\Adminhtml\Sync;

    class Now extends \Magento\Backend\App\Action
    {
        /**
        * @var \Magento\Framework\View\Result\PageFactory
        */
        protected $jsonResultFactory;

        /**
         * Constructor
         *
         * @param \Magento\Backend\App\Action\Context $context
         * @param \Magento\Framework\View\Result\JsonFactory $jsonResultFactory
         */
        public function __construct(
            \Magento\Backend\App\Action\Context $context,
            \Magento\Framework\Controller\Result\JsonFactory $jsonResultFactory,
            \Tagalys\Sync\Helper\Sync $tagalysSync
        ) {
             parent::__construct($context);
             $this->jsonResultFactory = $jsonResultFactory;
             $this->tagalysSync = $tagalysSync;
        }

        /**
         * Load the page defined in view/adminhtml/layout/exampleadminnewpage_helloworld_index.xml
         *
         * @return \Magento\Framework\View\Result\Page
         */
        public function execute()
        {
             $resultJson = $this->jsonResultFactory->create();

            //  $this->tagalysSync->sync(50, 5);

             $syncStatus = $this->tagalysSync->status();

             $resultJson->setData($syncStatus);
             return $resultJson;
        }
    }