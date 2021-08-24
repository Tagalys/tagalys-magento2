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

    public function afterMove(\Magento\Catalog\Model\Category $category, $result) {
        try {
            $affectedCategoryIds = $category->getAllChildren(true);
            $categories = $this->tagalysCategoryFactory->create()->getCollection()
                ->addFieldToFilter('category_id', $affectedCategoryIds)
                ->addFieldToFilter('status', 'powered_by_tagalys')
                ->addFieldToFilter('marked_for_deletion', 0);
            foreach ($categories as $category) {
                $category->setStatus('pending_sync')->save();
            }
            $this->auditLog->logInfo("CategoryPlugin::afterMove", "Marking moved categories as pending_sync", ['category_ids' => $affectedCategoryIds, 'result' => $result]);
        } catch (\Throwable $e) { }
    }

    public function beforeMove(\Magento\Catalog\Model\Category $category, $newParentId, $afterCategoryId) {
        $this->auditLog->logInfo("CategoryPlugin::beforeMove", "Category moved", ['category_id' => $category->getId(), 'old_parent_id' => $category->getParentId(), 'new_parent_id' => $newParentId]);
    }
}
?>
