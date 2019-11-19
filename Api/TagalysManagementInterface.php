<?php

namespace Tagalys\Sync\Api;


interface TagalysManagementInterface
{


    /**
     * POST for Info api
     * @param mixed $params
     * @return string
     */
    // ALERT: Test this in 2.0 - 2.1
    public function info($params);

    /**
     * POST for Sync api
     * @param mixed $params
     * @return string
     */
    // ALERT: Test this in 2.0 - 2.1
    public function syncCallback($params);

    /**
     * POST for Categories api
     * @param mixed $category
     * @return string
     */
    public function categorySave($category);

    /**
     * POST for Categories api
     * @param int $categoryId
     * @return string
     */
    public function categoryDelete($categoryId);
    
    /**
     * POST for Categories api
     * @param mixed $storeIds
     * @param int $categoryId
     * @return string
     */
    public function categoryDisable($storeIds, $categoryId);
}
