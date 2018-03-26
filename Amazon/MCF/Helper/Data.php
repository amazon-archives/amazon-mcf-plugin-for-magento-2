<?php
/**
 * Copyright 2017 Amazon.com, Inc. or its affiliates. All Rights Reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License").
 * You may not use this file except in compliance with the License.
 * A copy of the License is located at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * or in the "license" file accompanying this file. This file is distributed
 * on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either
 * express or implied. See the License for the specific language governing
 * permissions and limitations under the License.
 *
 */

namespace Amazon\MCF\Helper;

use \Magento\Framework\App\Helper\AbstractHelper;
use \Magento\Framework\App\Helper\Context;
use \Magento\Variable\Model\VariableFactory;
use \Monolog\Logger;

/**
 * Amazon MCF helper
 */
class Data extends AbstractHelper
{

    const CONFIG_APPLICATION_NAME = 'Amazon MCF Magento 2 Connector';

    const CONFIG_APPLICATION_VERSION = '0.1.0';

    const CONFIG_PATH_ENABLED = 'amazon_fba_connect/amazon_fba_credentials/amazon_fba_enable';

    const CONFIG_PATH_DEBUG = 'amazon_fba_connect/amazon_fba_dev_settings/amazon_fba_debug';

    const CONFIG_PATH_ENDPOINT = 'amazon_fba_connect/amazon_fba_credentials/amazon_fba_marketplace_endpoint';

    const CONFIG_PATH_CUSTOM_ENDPOINT = 'amazon_fba_connect/amazon_fba_credentials/amazon_fba_custom_endpoint';

    const CONFIG_PATH_SELLER_ID = 'amazon_fba_connect/amazon_fba_credentials/amazon_fba_seller_id';

    const CONFIG_PATH_ACCESS_KEY_ID = 'amazon_fba_connect/amazon_fba_credentials/amazon_fba_access_key_id';

    const CONFIG_PATH_SECRET_ACCESS_KEY = 'amazon_fba_connect/amazon_fba_credentials/amazon_fba_secret_access_key';

    const CONFIG_PATH_DISPLAY_DELIVERY_BLOCK
        = 'amazon_fba_connect/amazon_fba_delivery_estimates/amazon_fba_display_delivery_block';

    const CONFIG_PATH_SEND_AMAZON_SHIP_CONFIRMATION
        = 'amazon_fba_connect/amazon_fba_delivery_options/amazon_fba_ship_confirmation';

    const CONFIG_PATH_DISPLAY_ESTIMATED_ARRIVAL
        = 'amazon_fba_connect/amazon_fba_delivery_estimates/amazon_fba_display_estimated_arrival';

    const CONFIG_PATH_DISPLAY_SHIPPING_COST
        = 'amazon_fba_connect/amazon_fba_delivery_estimates/amazon_fba_display_shipping_cost';

    const CONFIG_PATH_PACKING_SLIP_COMMENT
        = 'amazon_fba_connect/amazon_fba_delivery_options/amazon_fba_packing_slip_comment';

    const CONFIG_PATH_DEFAULT_STANDARD_SHIPPING_COST
        = 'amazon_fba_connect/amazon_fba_delivery_estimates/amazon_fba_default_standard_cost';

    const CONFIG_PATH_DEFAULT_EXPEDITED_SHIPPING_COST
        = 'amazon_fba_connect/amazon_fba_delivery_estimates/amazon_fba_default_expedited_cost';

    const CONFIG_PATH_DEFAULT_PRIORITY_SHIPPING_COST
        = 'amazon_fba_connect/amazon_fba_delivery_estimates/amazon_fba_default_priority_cost';

    const CONFIG_PATH_LOG_API_REQUEST_RESPONSE
        = 'amazon_fba_connect/amazon_fba_dev_settings/amazon_fba_log_api';

    const CONFIG_PATH_LOG_ORDER_INVENTORY_PROCESSING
        = 'amazon_fba_connect/amazon_fba_dev_settings/log_order_inventory_processing';

    const CONFIG_PATH_CARRIER_ENABLED = 'carriers/amazonfulfillment/active';

    const CORE_VAR_INVENTORY_SYNC_PAGE = 'amazon_mcf_inventory_sync_page';

    const CORE_VAR_INVENTORY_SYNC_RUNNING = 'amazon_mcf_inventory_sync_running';

    const CONFIG_INVENTORY_ROW_COUNT = 45;

    const CORE_VAR_INVENTORY_SYNC_TOKEN = 'amazon_mcf_inventory_sync_token';

    const CORE_VAR_ORDER_SYNC_TOKEN = 'amazon_mcf_order_sync_token';

    const CORE_VAR_ORDER_SYNC_RUNNING = 'amazon_mcf_order_sync_running';

    const CORE_VAR_ORDER_SYNC_PAGE = 'amazon_mcf_order_sync_page';

    /**
     * Amazon Order Submission Status
     * new/attempted/fail - Order has not yet been successfully submitted to
     * Amazon The rest are Amazon order states invalid - these last three the
     * order is not going to be fulfilled by Amazon
     */
    const ORDER_STATUS_NEW = 'new';

    const ORDER_STATUS_ATTEMPTED = 'attempted';

    const ORDER_STATUS_FAIL = 'fail';

    const ORDER_STATUS_RECEIVED = 'received';

    const ORDER_STATUS_PLANNING = 'planning';

    const ORDER_STATUS_PROCESSING = 'processing';

    const ORDER_STATUS_COMPLETE = 'complete';

    const ORDER_STATUS_COMPLETE_PARTIALLED = 'complete_partialled';

    const ORDER_STATUS_INVALID = 'invalid';

    const ORDER_STATUS_CANCELLED = 'cancelled';

    const ORDER_STATUS_UNFULFILLABLE = 'unfulfillable';

    /**
     * @var \Magento\Variable\Model\Variable
     */
    private $variableFactory;

    /**
     * @var \Amazon\MCF\Logger\Logger
     */
    private $customLogger;

    /**
     * @var \Magento\Framework\Notification\NotifierPool
     */
    private $notifierPool;

    /**
     * @var \Magento\Directory\Api\CountryInformationAcquirerInterface
     */
    private $countryInformation;

    /**
     * @var \Magento\Framework\Encryption\EncryptorInterface
     */
    private $encryptor;

    /**
     * Data constructor.
     *
     * @param \Magento\Framework\App\Helper\Context                      $context
     * @param \Magento\Variable\Model\VariableFactory                    $variableFactory
     * @param \Magento\Directory\Api\CountryInformationAcquirerInterface $countryInformation
     * @param \Magento\Framework\Notification\NotifierPool               $notifierPool
     * @param \Magento\Framework\Encryption\EncryptorInterface           $encryptor
     */
    public function __construct(
        Context $context,
        VariableFactory $variableFactory,
        \Magento\Directory\Api\CountryInformationAcquirerInterface $countryInformation,
        \Magento\Framework\Notification\NotifierPool $notifierPool,
        \Magento\Framework\Encryption\EncryptorInterface $encryptor,
        \Amazon\MCF\Logger\Logger $customLogger
    ) {
        $this->variableFactory = $variableFactory;
        $this->notifierPool = $notifierPool;
        $this->countryInformation = $countryInformation;
        $this->encryptor = $encryptor;
        $this->customLogger = $customLogger;
        parent::__construct($context);
    }

    /**
     * Get application name
     *
     * @return string
     */
    public function getApplicationName()
    {
        return self::CONFIG_APPLICATION_NAME;
    }

    /**
     * Get application version
     *
     * @return string
     */
    public function getApplicationVersion()
    {
        return self::CONFIG_APPLICATION_VERSION;
    }

    /**
     * Is Multi Channel Fulfillment enabled and configured?
     *
     * @param string $storeId
     *
     * @return bool
     */
    public function isEnabled($storeId = '')
    {
        if ($this->verifyConfig()) {
            if ($storeId) {
                return $this->scopeConfig->getValue(
                    self::CONFIG_PATH_ENABLED,
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                    $storeId
                );
            }
            return $this->scopeConfig->getValue(self::CONFIG_PATH_ENABLED);
        }

        return 0;
    }

    /**
     * Check to see if Amazon MCF Carrier is enabled.
     *
     * @param string $storeId
     *
     * @return mixed
     */
    public function amazonCarrierEnabled($storeId = '')
    {
        if ($storeId) {
            return $this->scopeConfig->getValue(
                self::CONFIG_PATH_CARRIER_ENABLED,
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                $storeId
            );
        }

        return $this->scopeConfig->getValue(self::CONFIG_PATH_CARRIER_ENABLED);
    }

    /**
     * Indicates whether or not Amazon shipping messages will be sent.
     *
     * @param string $storeId
     *
     * @return mixed
     */
    public function sendAmazonShipConfirmation($storeId = '')
    {
        if ($storeId) {
            return $this->scopeConfig->getValue(
                self::CONFIG_PATH_SEND_AMAZON_SHIP_CONFIRMATION,
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                $storeId
            );
        }

        return $this->scopeConfig->getValue(self::CONFIG_PATH_SEND_AMAZON_SHIP_CONFIRMATION);
    }

    /**
     * Show Amazon shipping costs if enabled, otherwise, show default value
     * only.
     *
     * @param string $storeId
     *
     * @return mixed
     */
    public function displayShippingCosts($storeId = '')
    {
        if ($storeId) {
            return $this->scopeConfig->getValue(
                self::CONFIG_PATH_DISPLAY_SHIPPING_COST,
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                $storeId
            );
        }

        return $this->scopeConfig->getValue(self::CONFIG_PATH_DISPLAY_SHIPPING_COST);
    }

    /**
     * Indicates whether or not delivery estimate block displays on product
     * detail page
     *
     * @param string $storeId
     *
     * @return int|mixed
     */
    public function displayDeliveryBlock($storeId = '')
    {
        if ($this->verifyConfig() && $this->amazonCarrierEnabled($storeId)) {
            if ($storeId) {
                return $this->scopeConfig->getValue(
                    self::CONFIG_PATH_DISPLAY_DELIVERY_BLOCK,
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                    $storeId
                );
            }
            return $this->scopeConfig->getValue(self::CONFIG_PATH_DISPLAY_DELIVERY_BLOCK);
        }

        return 0;
    }

    /**
     * Returns a store's ISO Country Code
     *
     * @param  string $storeId
     * @return string
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getStoreCountry($storeId = '')
    {
        // If no country data is available we'll assume US as default
        $country = 'US';

        if ($storeId) {
            $countryId = $this->scopeConfig->getValue(
                'general/store_information/country_id',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                $storeId
            );
        } else {
            return $this->scopeConfig->getValue('general/country/default');
        }

        if ($countryId) {
            $countryInfo = $this->countryInformation->getCountryInfo($countryId);
            if ($countryInfo) {
                $country = $countryInfo->getTwoLetterAbbreviation();
            }
        } else {
            return $this->scopeConfig->getValue('general/country/default');
        }

        return $country;
    }

    /**
     * Indicates whether or not to display shipping estimator block on product
     * pages
     *
     * @param string $storeId
     *
     * @return int|mixed
     */
    public function displayEstimatedArrival($storeId = '')
    {
        if ($this->verifyConfig()) {
            if ($storeId) {
                return $this->scopeConfig->getValue(
                    self::CONFIG_PATH_DISPLAY_ESTIMATED_ARRIVAL,
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                    $storeId
                );
            }
            return $this->scopeConfig->getValue(self::CONFIG_PATH_DISPLAY_ESTIMATED_ARRIVAL);
        }

        return 0;
    }

    /**
     * Get custom packing slip message
     *
     * @param string $storeId
     *
     * @return mixed|string
     */
    public function getPackingSlipComment($storeId = '')
    {
        $message = __('Thank you for your order!');
        $value = '';

        if ($this->verifyConfig()) {
            if ($storeId) {
                $value = $this->scopeConfig->getValue(
                    self::CONFIG_PATH_PACKING_SLIP_COMMENT,
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                    $storeId
                );
            }
            $value = $this->scopeConfig->getValue(self::CONFIG_PATH_PACKING_SLIP_COMMENT);
        }

        if ($value) {
            $message = $value;
        }

        return $message;
    }

    /**
     * Get default standard shipping cost.
     *
     * @param string $storeId
     *
     * @return mixed|string
     */
    public function getDefaultStandardShippingCost($storeId = '')
    {

        if ($this->verifyConfig()) {
            if ($storeId) {
                return $this->scopeConfig->getValue(
                    self::CONFIG_PATH_DEFAULT_STANDARD_SHIPPING_COST,
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                    $storeId
                );
            }
            return $this->scopeConfig->getValue(self::CONFIG_PATH_DEFAULT_STANDARD_SHIPPING_COST);
        }

        return '';
    }

    /**
     * Get default expedited shipping cost.
     *
     * @param string $storeId
     *
     * @return mixed|string
     */
    public function getDefaultExpeditedShippingCost($storeId = '')
    {
        if ($this->verifyConfig()) {
            if ($storeId) {
                return $this->scopeConfig->getValue(
                    self::CONFIG_PATH_DEFAULT_EXPEDITED_SHIPPING_COST,
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                    $storeId
                );
            }
            return $this->scopeConfig->getValue(self::CONFIG_PATH_DEFAULT_EXPEDITED_SHIPPING_COST);
        }

        return '';
    }

    /**
     * Get default priority shipping cost.
     *
     * @param string $storeId
     *
     * @return mixed|string
     */
    public function getDefaultPriorityShippingCost($storeId = '')
    {
        if ($this->verifyConfig()) {
            if ($storeId) {
                return $this->scopeConfig->getValue(
                    self::CONFIG_PATH_DEFAULT_PRIORITY_SHIPPING_COST,
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                    $storeId
                );
            }
            return $this->scopeConfig->getValue(self::CONFIG_PATH_DEFAULT_PRIORITY_SHIPPING_COST);
        }

        return '';
    }

    /**
     * Is debug mode turned on?
     *
     * @return bool
     */
    public function isDebug()
    {
        return $this->scopeConfig->getValue(self::CONFIG_PATH_DEBUG);
    }

    /**
     * Get endpoint path for MCF calls
     *
     * @param string $storeId
     *
     * @return mixed
     */
    public function getEndpoint($storeId = '')
    {
        if ($storeId) {
            $endpoint = $this->scopeConfig->getValue(
                self::CONFIG_PATH_PACKING_SLIP_COMMENT,
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                $storeId
            );
            $customEndpoint = $this->scopeConfig->getValue(
                self::CONFIG_PATH_CUSTOM_ENDPOINT,
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                $storeId
            );
        } else {
            $endpoint = $this->scopeConfig->getValue(self::CONFIG_PATH_ENDPOINT);
            $customEndpoint = $this->scopeConfig->getValue(self::CONFIG_PATH_CUSTOM_ENDPOINT);
        }

        if ($endpoint == 'custom' && $customEndpoint) {
            $endpoint = $customEndpoint;
        }

        return $endpoint;
    }

    /**
     * Get seller ID
     *
     * @param string $storeId
     *
     * @return mixed
     */
    public function getSellerId($storeId = '')
    {
        if ($storeId) {
            return $this->scopeConfig->getValue(
                self::CONFIG_PATH_SELLER_ID,
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                $storeId
            );
        }
        return $this->scopeConfig->getValue(self::CONFIG_PATH_SELLER_ID);
    }

    /**
     * Get access key ID
     *
     * @param string $storeId
     *
     * @return mixed
     */
    public function getAccessKeyId($storeId = '')
    {
        $key = '';

        if ($storeId) {
            $key = $this->scopeConfig->getValue(
                self::CONFIG_PATH_ACCESS_KEY_ID,
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                $storeId
            );
        } else {
            $key = $this->scopeConfig->getValue(self::CONFIG_PATH_ACCESS_KEY_ID);
        }

        if ($key) {
            $key = $this->encryptor->decrypt($key);
        }
        return $key;
    }

    /**
     * Get secret access key
     *
     * @param string $storeId
     *
     * @return mixed
     */
    public function getSecretAccessKey($storeId = '')
    {
        $key = '';

        if ($storeId) {
            $key = $this->scopeConfig->getValue(
                self::CONFIG_PATH_SECRET_ACCESS_KEY,
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                $storeId
            );
        } else {
            $key = $this->scopeConfig->getValue(self::CONFIG_PATH_SECRET_ACCESS_KEY);
        }

        if ($key) {
            $key = $this->encryptor->decrypt($key);
        }
        return $key;
    }

    /**
     * Is logging turned on?
     *
     * @param string $storeId
     *
     * @return mixed
     */
    public function getLogApi($storeId = '')
    {
        if ($storeId) {
            return $this->scopeConfig->getValue(
                self::CONFIG_PATH_LOG_API_REQUEST_RESPONSE,
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                $storeId
            );
        }
        return $this->scopeConfig->getValue(self::CONFIG_PATH_LOG_API_REQUEST_RESPONSE);
    }

    /**
     * Is logging turned on for orders and inventory?
     *
     * @param string $storeId
     *
     * @return mixed
     */
    public function getLogOrderInventoryProcessing($storeId = '')
    {
        if ($storeId) {
            return $this->scopeConfig->getValue(
                self::CONFIG_PATH_LOG_ORDER_INVENTORY_PROCESSING,
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                $storeId
            );
        }
        return $this->scopeConfig->getValue(self::CONFIG_PATH_LOG_ORDER_INVENTORY_PROCESSING);
    }

    /**
     * Verify that config fields have values.
     *
     * @return bool
     */
    private function verifyConfig()
    {
        $endpoint = $this->getEndpoint();
        $sellerId = $this->getSellerId();
        $accessKeyId = $this->getAccessKeyId();
        $secretAccessKey = $this->getSecretAccessKey();

        // check that none of the required config fields are empty
        if (!empty($endpoint)
            && !empty($accessKeyId)
            && !empty($secretAccessKey)
            && !empty($sellerId)
        ) {
            return true;
        } else {
            $this->notifierPool->info(
                'Amazon Multi-Channel Fulfillment',
                'Seller Central keys or endpoint are not set correctly. Please check your configuration and try again.'
            );

            return false;
        }
    }

    /**
     * Get current row value for product/stock processing progress
     *
     * @return string
     */
    public function getInventoryProcessRow()
    {
        return $this->variableFactory->create()
            ->loadByCode(self::CORE_VAR_INVENTORY_SYNC_PAGE)
            ->getValue('plain');
    }

    /**
     * Set current row count for product/stock processing
     *
     * @param $rowCount
     */
    public function setInventoryProcessRow($rowCount)
    {
        $variable = $this->variableFactory->create()
            ->loadByCode(self::CORE_VAR_INVENTORY_SYNC_PAGE);

        $data = [
            'code' => self::CORE_VAR_INVENTORY_SYNC_PAGE,
            'name' => self::CORE_VAR_INVENTORY_SYNC_PAGE,
            'html_value' => $rowCount,
            'plain_value' => $rowCount,
        ];

        if (empty($variable)) {
            $variable = $this->variableFactory->create();
            $variable->setData($data);
            $variable->save();
        } else {
            foreach ($data as $key => $value) {
                $variable->setData($key, $value);
            }
            $variable->save();
        }
    }

    /**
     * Get current status of inventory processing job
     *
     * @return boolean
     */
    public function getInventoryProcessStatus()
    {
        return $this->variableFactory->create()
            ->loadByCode(self::CORE_VAR_INVENTORY_SYNC_RUNNING)
            ->getValue('plain');
    }

    /**
     * Set status of inventory processing job
     *
     * @param $status
     */
    public function setInventoryProcessStatus($status)
    {
        $variable = $this->variableFactory->create()
            ->loadByCode(self::CORE_VAR_INVENTORY_SYNC_RUNNING);

        $data = [
            'code' => self::CORE_VAR_INVENTORY_SYNC_RUNNING,
            'name' => self::CORE_VAR_INVENTORY_SYNC_RUNNING,
            'html_value' => $status,
            'plain_value' => $status,
        ];

        if (empty($variable)) {
            $variable = $this->variableFactory->create();
            $variable->setData($data);
            $variable->save();
        } else {
            foreach ($data as $key => $value) {
                $variable->setData($key, $value);
            }
            $variable->save();
        }
    }

    /**
     * Get current row value for product/stock processing progress
     *
     * @return string
     */
    public function getInventoryRowCount()
    {
        return self::CONFIG_INVENTORY_ROW_COUNT;
    }

    /**
     * Get next token to process additional data from scheduled inventory
     * supply list call
     *
     * @return string
     */
    public function getInventoryNextToken()
    {
        return $this->variableFactory->create()
            ->loadByCode(self::CORE_VAR_INVENTORY_SYNC_TOKEN)
            ->getValue('plain');
    }

    /**
     * Set next token if one exists
     *
     * @param $nextToken
     */
    public function setInventoryNextToken($nextToken)
    {
        $variable = $this->variableFactory->create()
            ->loadByCode(self::CORE_VAR_INVENTORY_SYNC_TOKEN);

        $data = [
            'code' => self::CORE_VAR_INVENTORY_SYNC_TOKEN,
            'name' => self::CORE_VAR_INVENTORY_SYNC_TOKEN,
            'html_value' => $nextToken,
            'plain_value' => $nextToken,
        ];

        if (empty($variable)) {
            $variable = $this->variableFactory->create();
            $variable->setData($data);
            $variable->save();
        } else {
            foreach ($data as $key => $value) {
                $variable->setData($key, $value);
            }
            $variable->save();
        }
    }

    /**
     * @return string
     */
    public function getOrderNextToken()
    {
        return $this->variableFactory->create()
            ->loadByCode(self::CORE_VAR_ORDER_SYNC_TOKEN)
            ->getValue('plain');
    }

    /**
     * @param string $nextToken
     */
    public function setOrderNextToken($nextToken = '')
    {
        $variable = $this->variableFactory->create()
            ->loadByCode(self::CORE_VAR_ORDER_SYNC_TOKEN);

        $data = [
            'code' => self::CORE_VAR_ORDER_SYNC_TOKEN,
            'name' => self::CORE_VAR_ORDER_SYNC_TOKEN,
            'html_value' => $nextToken,
            'plain_value' => $nextToken,
        ];

        if (empty($variable)) {
            $variable = $this->variableFactory->create();
            $variable->setData($data);
            $variable->save();
        } else {
            foreach ($data as $key => $value) {
                $variable->setData($key, $value);
            }
            $variable->save();
        }
    }

    /**
     * @return string
     */
    public function getOrderProcessRunning()
    {
        return $this->variableFactory->create()
            ->loadByCode(self::CORE_VAR_ORDER_SYNC_RUNNING)
            ->getValue('plain');
    }

    /**
     * @param string $status
     */
    public function setOrderProcessRunning($status = '')
    {

        $variable = $this->variableFactory->create()
            ->loadByCode(self::CORE_VAR_ORDER_SYNC_RUNNING);

        $data = [
            'code' => self::CORE_VAR_ORDER_SYNC_RUNNING,
            'name' => self::CORE_VAR_ORDER_SYNC_RUNNING,
            'html_value' => $status,
            'plain_value' => $status,
        ];

        if (empty($variable)) {
            $variable = $this->variableFactory->create();
            $variable->setData($data);
            $variable->save();
        } else {
            foreach ($data as $key => $value) {
                $variable->setData($key, $value);
            }
            $variable->save();
        }
    }

    /**
     * @return int|string
     */
    public function getOrderProcessPage()
    {
        $value = $this->variableFactory->create()
            ->loadByCode(self::CORE_VAR_ORDER_SYNC_PAGE)
            ->getValue('plain');

        if (empty($value) || !$value) {
            $value = 1;
        }

        return $value;
    }

    /**
     * @param int $page
     */
    public function setOrderProcessPage($page = 0)
    {
        $variable = $this->variableFactory->create()
            ->loadByCode(self::CORE_VAR_ORDER_SYNC_PAGE);

        $data = [
            'code' => self::CORE_VAR_ORDER_SYNC_PAGE,
            'name' => self::CORE_VAR_ORDER_SYNC_PAGE,
            'html_value' => $page,
            'plain_value' => $page,
        ];

        if (empty($variable)) {
            $variable = $this->variableFactory->create();
            $variable->setData($data);
            $variable->save();
        } else {
            foreach ($data as $key => $value) {
                $variable->setData($key, $value);
            }
            $variable->save();
        }
    }

    /**
     * @param $string
     * @param null   $store
     */
    public function logApi($string, $store = null)
    {
        if ($this->getLogApi($store)) {
            $this->customLogger->debug($string);
        }
    }

    /**
     * @param $string
     * @param null   $store
     */
    public function logOrder($string, $store = null)
    {
        if ($this->getLogOrderInventoryProcessing($store)) {
            $this->customLogger->debug('Order Update: ' . $string);
        }
    }

    /**
     * @param $string
     * @param null   $store
     */
    public function logInventory($string, $store = null)
    {
        if ($this->getLogOrderInventoryProcessing($store)) {
            $this->customLogger->debug('Inventory Update: ' . $string);
        }
    }

    /**
     * @param $method
     * @param \FBAOutboundServiceMWS_Exception $e
     * @param null                             $store
     */
    public function logApiError($method, \FBAOutboundServiceMWS_Exception $e, $store = null)
    {
        if ($this->getLogApi($store)) {
            $this->logApi(
                'Error in ' . $method . ', response: '
                . $e->getErrorCode() . ': ' . $e->getErrorMessage() . "\n" . $e->getXML()
            );
        }
    }
}
