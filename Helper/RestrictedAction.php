<?php

namespace Tagalys\Sync\Helper;

class RestrictedAction
{

    private $tagalysConfiguration;
    private $tagalysApi;
    private $namespace;
    private $lockId;
    private $data;

    public function __construct(
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration,
        \Tagalys\Sync\Helper\Api $tagalysApi
    ) {
        $this->tagalysConfiguration = $tagalysConfiguration;
        $this->tagalysApi = $tagalysApi;
        $this->namespace = null;
        $this->lockId = null;
        $this->data = null;
    }

    public static function getInstance()
    {
        return Utils::getInstanceOf('\Tagalys\Sync\Helper\RestrictedAction');
    }

    // * Don't use random values for namespace, as each namespace will create a row in tagalys_config table.
    // Should we move this class's data into a separate table?
    public function setNamespace($namespace)
    {
        $this->namespace = $namespace;
    }

    public function lock()
    {
        if (empty($this->namespace)) {
            throw new \Exception("namespace is not set");
        }
        $dbValue = $this->readValue();
        $this->lockId = uniqid();
        if ($dbValue && $dbValue['lock_id']) {
            if ($this->hasPastOwnerDied($dbValue)) {
                $this->tagalysApi->log(
                    'warn',
                    "RestrictedAction force unlocked for namespace: {$this->namespace}",
                    [
                        "namespace" => $this->namespace,
                        "db_value" => $dbValue,
                        "new_lock_id" => $this->lockId,
                        "now" => Utils::now()
                    ]
                );
            } else {
                // Valid lock already found
                return false;
            }
        }
        $this->writeValue([
            'lock_id' => $this->lockId,
            'updated_at' => Utils::now(),
        ]);
        return true;
    }

    public function unlock()
    {
        if ($this->isOwned()) {
            $this->updateValue(['lock_id' =>  null]);
            return true;
        }
        return false;
    }

    public function isOwned($considerUpdatedAt = false)
    {
        $dbValue = $this->readValue();
        $owned = ($this->lockId && $dbValue && $dbValue['lock_id'] == $this->lockId);
        if ($owned && $considerUpdatedAt && $this->hasPastOwnerDied($dbValue)) {
            return false;
        }
        return $owned;
    }

    public function tryExecute($block)
    {
        if ($this->lock()) {
            try {
                $block();
            } finally {
                $this->unlock();
            }
            return true;
        }
        return false;
    }

    public function renewLock()
    {
        return $this->updateValue(['updated_at' => Utils::now()]);
    }

    public function setData($data)
    {
        $owned = $this->isOwned();
        if ($owned) {
            $this->updateValue(['data' => $data]);
        }
        return $owned;
    }

    private function hasPastOwnerDied($dbValue)
    {
        $interval = Utils::getIntervalInSeconds($dbValue['updated_at'], Utils::now());
        $tenMinutes = 10 * 60;
        if ($interval < $tenMinutes) {
            // * Updated less than 10 min ago, so valid.
            return false;
        }
        return true;
    }

    private function readValue()
    {
        return $this->tagalysConfiguration->getConfig($this->getCacheKey(), true);
    }

    private function writeValue($value)
    {
        return $this->tagalysConfiguration->setConfig($this->getCacheKey(), $value, true);
    }

    private function updateValue($updateData)
    {
        return $this->tagalysConfiguration->updateJsonConfig($this->getCacheKey(), $updateData);
    }

    private function clearValue()
    {
        return $this->tagalysConfiguration->clearConfig($this->getCacheKey());
    }

    private function getCacheKey()
    {
        return "ra:{$this->namespace}";
    }
}
