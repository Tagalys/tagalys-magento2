<?php
namespace Tagalys\Sync\Plugin;

class CategoryPlugin
{
    /**
     * @param \Tagalys\Sync\Model\CategoryFactory
     */
    private $tagalysCategoryFactory;

    public function __construct(
        \Tagalys\Sync\Model\CategoryFactory $tagalysCategoryFactory,
        \Magento\Catalog\Model\CategoryFactory $categoryFactory
    ) {
        $this->tagalysCategoryFactory = $tagalysCategoryFactory;
    }

    public function afterMove(\Magento\Catalog\Model\Category $category, $result)
    {
        $affectedCategoryIds = $category->getAllChildren(true);
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
