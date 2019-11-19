<?php
namespace Tagalys\Sync\Api\Data;


interface ConfigInterface
{
    /**
     * Constants for keys of data array. Identical to the name of the getter in snake case
     */
    const ID = 'id';
    const PATH = 'path';
    const VALUE = 'value';

    /**
     * Get ID
     *
     * @return int|null
     */
    public function getId();

    public function getPath();

    public function getValue();


    /**
     * Set ID
     *
     * @param int $id
     * @return \Ashsmith\Blog\Api\Data\PostInterface
     */
    public function setId($id);

    public function setPath($path);

    public function setValue($value);
}