<?php
namespace Tagalys\Sync\Api\Data;


interface QueueInterface
{
    /**
     * Constants for keys of data array. Identical to the name of the getter in snake case
     */
    const ID = 'id';
    const PRODUCT_ID = 'product_id';

    /**
     * Get ID
     *
     * @return int|null
     */
    public function getId();

    public function getProductId();


    /**
     * Set ID
     *
     * @param int $id
     * @return \Ashsmith\Blog\Api\Data\PostInterface
     */
    public function setId($id);

    public function setProductId($path);
}