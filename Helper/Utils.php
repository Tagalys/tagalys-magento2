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

    public static function getExceptionDetails($e) {
        return [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTrace(),
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
}
