<?php
namespace Tagalys\Sync\Cron;

class CategorySync extends Cron
{
    /**
     * @param \Tagalys\Sync\Helper\Category
     */
    private $tagalysCategory;

    public function __construct(
        \Magento\Framework\App\State $appState,
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration,
        \Tagalys\Sync\Helper\Category $tagalysCategory
    ) {
        parent::__construct($appState, $tagalysConfiguration);
        $this->tagalysCategory = $tagalysCategory;
    }

    protected function heartbeatName() {
        return "category_sync";
    }

    protected function perform() {
        $this->tagalysCategory->sync();
    }
}
?>
