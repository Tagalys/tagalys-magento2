<?php
namespace Tagalys\Sync\Helper;

class Api extends \Magento\Framework\App\Helper\AbstractHelper
{
    public function __construct(
        \Magento\Framework\App\ProductMetadataInterface $productMetadataInterface,
        \Magento\Framework\Module\ModuleListInterface $moduleListInterface,
        \Tagalys\Sync\Model\ConfigFactory $configFactory,
        \Magento\Store\Model\StoreManagerInterface $storeManager
    )
    {
        $this->timeout = 60;
        $this->productMetadataInterface = $productMetadataInterface;
        $this->moduleListInterface = $moduleListInterface;
        $this->configFactory = $configFactory;
        $this->storeManager = $storeManager;

        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/tagalys_log.log');
        $this->tagalysLogger = new \Zend\Log\Logger();
        $this->tagalysLogger->addWriter($writer);

        $this->pluginVersion = '2.1.2';

        $this->cacheApiCredentials();
    }

    public function getPluginVersion() {
        return $this->pluginVersion;
    }

    public function cacheApiCredentials() {
        try {
            $configValue = $this->configFactory->create()->load('api_credentials')->getValue();
            if ($configValue != NULL) {
                $apiCredentials = json_decode($configValue, true);
                $this->apiServer = $apiCredentials['api_server'];
                $this->clientCode = $apiCredentials['client_code'];
                $this->privateApiKey = $apiCredentials['private_api_key'];
                $this->publicApiKey = $apiCredentials['public_api_key'];
            }
        } catch (\Exception $e) {
            $this->apiServer = false;
        }
    }

    public function log($level, $message, $data = null) {
        $this->tagalysLogger->info(json_encode(compact('level', 'message', 'data')));
        if ($this->apiServer != false && $level != 'local') {
            $logParams = array('log_level' => $level, 'log_message' => $message);
            $logParams['log_client'] = array('platform' => $this->platformIdentifier(), 'platform_version' => $this->productMetadataInterface->getVersion(), 'plugin' => 'Tagalys_Sync', 'plugin_version' => $this->getPluginVersion());
            if ($data != null) {
                if (array_key_exists('store_id', $data)) {
                    $logParams['log_store_id'] = $data['store_id'];
                    unset($data['store_id']);
                }
                $logParams['log_data'] = $data;
            }
            $this->clientApiCall('/v1/clients/log', $logParams);
        }
    }

    public function identificationCheck($apiCredentials) {
        try {
            $this->apiServer = $apiCredentials['api_server'];
            $response = $this->_apiCall('/v1/identification/check', array(
                'identification' => array(
                    'client_code' => $apiCredentials['client_code'],
                    'public_api_key' => $apiCredentials['public_api_key'],
                    'private_api_key' => $apiCredentials['private_api_key']
                )
            ));
            return $response;
        } catch (\Exception $e) {
            $this->tagalysLogger->warn("Exception in Api.php identificationCheck: {$e->getMessage()}; \$apiCredentials: " . json_encode($apiCredentials));
         return false;
        }
    }

    public function clientApiCall($path, $params) {
        $params['identification'] = array(
            'client_code' => $this->clientCode,
            'api_key' => $this->privateApiKey
        );
        return $this->_apiCall($path, $params);
    }
    public function storeApiCall($storeId, $path, $params = []) {
        $storeUrl = $this->storeManager->getStore($storeId)->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB, true);
        $storeDomain = parse_url($storeUrl)['host'];
        $params['identification'] = array(
            'client_code' => $this->clientCode,
            'api_key' => $this->privateApiKey,
            'store_id' => $storeId,
            'domain' => $storeDomain
        );
        return $this->_apiCall($path, $params);
    }

    public function platformVersionMajor() {
        return explode('.', $this->productMetadataInterface->getVersion())[0];
    }
    public function platformIdentifier() {
        return 'Magento-' . $this->productMetadataInterface->getEdition() . '-' . $this->platformVersionMajor();
    }

    private function _apiCall($path, $params) {
        try {
            if ($this->apiServer === false) {
                $this->tagalysLogger->warn("Error in Api.php _apiCall: \$this->apiServer is false; path: $path; params: " . json_encode($params));
                return false;
            }
            if (array_key_exists('identification', $params)) {
                $params['identification']['api_client'] = array('platform' => $this->platformIdentifier(), 'platform_version' => $this->productMetadataInterface->getVersion(), 'plugin' => 'Tagalys_Sync', 'plugin_version' => $this->getPluginVersion());
            }
            $url = $this->apiServer . $path;
            $curlHandle = curl_init($url);
            $port = parse_url($url, PHP_URL_PORT);
            if ($port != NULL) {
                curl_setopt($curlHandle, CURLOPT_PORT, $port);
            }
            curl_setopt($curlHandle, CURLOPT_POST, 1);
            curl_setopt($curlHandle, CURLOPT_POSTFIELDS, json_encode($params));
            curl_setopt($curlHandle, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
            curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curlHandle, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($curlHandle, CURLOPT_TIMEOUT, $this->timeout);
            $response = curl_exec($curlHandle);
            if (curl_errno($curlHandle)) {
                $http_code = curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
                $this->tagalysLogger->warn("Error in Api.php _apiCall: curl error ($http_code); api_server: $this->apiServer; path: $path; params: " . json_encode($params));
                return false;
            }
            if (empty($response)) {
                $this->tagalysLogger->warn("Error in Api.php _apiCall: response is empty; api_server: $this->apiServer; path: $path; params: " . json_encode($params));
                return false;
            }
            curl_close($curlHandle);
            $decoded = json_decode($response, true);
            if ($decoded === NULL) {
                $this->tagalysLogger->warn("Error in Api.php _apiCall: decoded is NULL; api_server: $this->apiServer; path: $path; params: " . json_encode($params));
                return false;
            }
            if ($decoded["status"] == "OK") {
                return $decoded;
            } else {
                return false;
            }
        } catch (\Exception $e) {
            $this->tagalysLogger->warn("Exception in Api.php _apiCall: {$e->getMessage()}; api_server: $this->apiServer; path: $path; params: " . json_encode($params));
            return false;
        }
    }
}
