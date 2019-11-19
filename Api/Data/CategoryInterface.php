<?php
namespace Tagalys\Sync\Api\Data;


interface CategoryInterface
{
  /**
   * Constants for keys of data array. Identical to the name of the getter in snake case
   */
  const ID = 'id';
  /**
   * Get ID
   *
   * @return int|null
   */
  public function getId();

  /**
   * Set ID
   *
   * @param int $id
   * @return \Ashsmith\Blog\Api\Data\PostInterface
   */
  public function setId($id);
}
