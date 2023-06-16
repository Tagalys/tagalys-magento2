<?php
namespace Tagalys\Sync\Helper;

class Utils
{
    public function __construct(){}

    public static function forEachChunk($array, $chunkSize, $callback) {
        $thisBatch = array_splice($array, 0, $chunkSize);
        while(count($thisBatch) > 0) {
            $callback($thisBatch);
            $thisBatch = array_splice($array, 0, $chunkSize);
        }
    }

    public static function getInstanceOf($class) {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        return $objectManager->create($class);
    }

    public static function findByKey($key, $value, $list) {
        foreach($list as $item) {
            if (array_key_exists($key, $item) && $item[$key] == $value) {
                return $item;
            }
        }
        return false;
    }

    public static function getExceptionDetails($e, $debugInfo = null) {
        return [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTrace(),
            'debug_info' => $debugInfo,
        ];
    }

    public static function isConfigurableProduct($product) {
        return ($product->getTypeId() == 'configurable');
    }
    public static function isBundleProduct($product) {
        return ($product->getTypeId() == 'bundle');
    }
    public static function isGroupedProduct($product) {
        return ($product->getTypeId() == 'grouped');
    }
    public static function isGiftCard($product) {
        return ($product->getTypeId() == 'giftcard');
    }

    public static function getAllIds($collection) {
        return array_map(function($item) {
            return $item['id'];
        }, $collection->toArray()['items']);
    }

    //* Accepted values for $logLevel are:
    // EMERG   = 0;  // Emergency: system is unusable
    // ALERT   = 1;  // Alert: action must be taken immediately
    // CRIT    = 2;  // Critical: critical conditions
    // ERR     = 3;  // Error: error conditions
    // WARN    = 4;  // Warning: warning conditions
    // NOTICE  = 5;  // Notice: normal but significant condition
    // INFO    = 6;  // Informational: informational messages
    // DEBUG   = 7;  // Debug: debug messages
    public static function getLogger($fileName, $logLevel = \Zend_Log::DEBUG) {
        $writer = new \Zend_Log_Writer_Stream(BP . "/var/log/$fileName");
        $logger = new \Zend_Log();
        $logger->addWriter($writer);
        $logger->addFilter(new \Zend_Log_Filter_Priority($logLevel, '<='));
        return $logger;
    }

    public static function now() {
        $utcNow = new \DateTime("now", new \DateTimeZone('UTC'));
        return $utcNow->format(\DateTime::ATOM);
    }

    public static function getIntervalInSeconds($from, $to)
    {
        if (is_string($from)) {
            $from = new \DateTime($from);
        }
        if (is_string($to)) {
            $to = new \DateTime($to);
        }
        return $to->getTimestamp() - $from->getTimestamp();
    }

    public static function fetchKey($array, $key, $default = null) {
        return array_key_exists($key, $array) ? $array[$key] : $default;
    }

    public static function camelize($input, $separator = '_') {
        return lcfirst(str_replace($separator, '', ucwords($input, $separator)));
    }

    public static function filterDuplicateHashes($arrayOfHashes, $identifierKey = 'id') {
        $uniques = [];
        $addedItems = [];
        foreach ($arrayOfHashes as $hash) {
            $identifier = $hash[$identifierKey];
            if(!isset($addedItems[$identifier])) {
                $addedItems[$identifier] = true;
                $uniques[] = $hash;
            }
        }
        return $uniques;
    }

    public static function endsWith($haystack, $needle) {
        return substr_compare($haystack, $needle, -strlen($needle)) === 0;
    }

    // dev helpers
    public static function dj($data, $lineBreaker = "\n") {
        echo json_encode($data) . "$lineBreaker";
    }
}
