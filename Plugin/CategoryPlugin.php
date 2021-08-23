<?php
namespace Tagalys\Sync\Plugin;

class CategoryPlugin
{
    /**
     * @param \Tagalys\Sync\Model\CategoryFactory
     */
    private $tagalysCategoryFactory;

    /**
     * @param \Tagalys\Sync\Helper\AuditLog
     */
    private $auditLog;

    public function __construct(
        \Tagalys\Sync\Model\CategoryFactory $tagalysCategoryFactory,
        \Tagalys\Sync\Helper\AuditLog $auditLog
    ) {
        $this->tagalysCategoryFactory = $tagalysCategoryFactory;
        $this->auditLog = $auditLog;
    }

    public function afterMove(\Magento\Catalog\Model\Category $category, $result)
    {
        $affectedCategoryIds = $category->getAllChildren(true);
        $this->auditLog->logInfo("CategoryPlugin::afterMove | Marking categories: {$affectedCategoryIds} as pending_sync");
        $categories = $this->tagalysCategoryFactory->create()->getCollection()
            ->addFieldToFilter('category_id', $affectedCategoryIds)
            ->addFieldToFilter('status', 'powered_by_tagalys')
            ->addFieldToFilter('marked_for_deletion', 0);
        foreach ($categories as $category) {
            $category->setStatus('pending_sync')->save();
        }
    }
}
?>
