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
 */

namespace Amazon\MCF\Model\Service;

use Amazon\MCF\Helper\Data;

/**
 *  * Handles calls to Amazon MCF Fulfillment Inventory
 *
 * Class Inventory
 *
 * @package Amazon\MCF\Model\Service
 */

class Inventory extends MCFAbstract
{

    const SERVICE_NAME = '/FulfillmentInventory/';

    const SERVICE_CLASS = 'FBAInventoryServiceMWS';

    /**
     * @var \Amazon\MCF\Helper\Data
     */
    protected $helper;

    /**
     * Outbound constructor.
     *
     * @param \Amazon\MCF\Helper\Data $helper
     */
    public function __construct(Data $helper)
    {
        parent::__construct($helper);
        $this->helper = $helper;

        include_once $this->getModulePath() . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'Amazon'
            . DIRECTORY_SEPARATOR . 'FBAInventoryServiceMWS' . DIRECTORY_SEPARATOR . 'Exception.php';
        include_once $this->getModulePath() . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'Amazon'
            . DIRECTORY_SEPARATOR . 'FBAInventoryServiceMWS' . DIRECTORY_SEPARATOR . 'Client.php';
        include_once $this->getModulePath() . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'Amazon'
            . DIRECTORY_SEPARATOR . 'FBAInventoryServiceMWS' . DIRECTORY_SEPARATOR . 'Mock.php';
    }


    /**
     * Gets a list of inventory details based on Skus passed in an array
     *
     * @param $sellerSKUs array
     *
     * @return mixed
     */
    public function getFulfillmentInventoryList($sellerSKUs = [], $startTime = '')
    {

        $client = $this->getClient();

        $request = [
            'SellerId' => $this->helper->getSellerId(),
            'SellerSkus' => $sellerSKUs
        ];

        if ($startTime && !$sellerSKUs) {
            $request['QueryStartDateTime'] = $startTime;
        }

        try {
            $inventory = $client->listInventorySupply($request);
        } catch (\FBAInventoryServiceMWS_Exception $e) {
            $this->helper->logInventory($this->getErrorDebugMessage($e));
            $inventory = null;
        }

        return $inventory;
    }

    /**
     * Checks to see if a simple call returns values or error
     *
     * @return mixed
     */
    public function checkCredentials()
    {

        $result = ['result' => 'success'];

        $message = __('Your keys are correct, and able to connect to Fulfillment by Amazon.');
        $client = $this->getClient();

        $startTime = gmdate("Y-m-d\TH:i:s.\\0\\0\\0\\Z", strtotime('-1 day'));

        $request = [
            'SellerId' => $this->helper->getSellerId(),
            'QueryStartDateTime' => $startTime
        ];

        try {
            $data = $client->listInventorySupply($request);
        } catch (\FBAInventoryServiceMWS_Exception $e) {
            $this->helper->logInventory($this->getErrorDebugMessage($e));
            $message = __(
                'Your Amazon MWS API developer credentials are not valid. Please verify keys were entered correctly, 
                and check user guide for more details on obtaining keys.'
            );
            $result['result'] = 'fail';
        }

        $result['message'] = $message;

        return $result;
    }

    /**
     * Get inventory values based on next token value
     *
     * @param $nextToken
     *
     * @return null
     */
    public function getListInventorySupplyByNextToken($nextToken)
    {

        $client = $this->getClient();

        $request = [
            'SellerId' => $this->helper->getSellerId(),
            'NextToken' => $nextToken,
        ];

        try {
            $inventory = $client->listInventorySupplyByNextToken($request);
        } catch (\FBAInventoryServiceMWS_Exception $e) {
            $this->helper->logInventory($this->getErrorDebugMessage($e));
            $inventory = null;
        }

        return $inventory;
    }

    /**
     * Extracts error message and preps it for use with logger
     *
     * @param FBAInventoryServiceMWS_Exception $e
     *
     * @return string
     */
    private function getErrorDebugMessage(\FBAInventoryServiceMWS_Exception $e)
    {

        $message = "Caught Exception: " . $e->getMessage() . ' ';
        $message .= "Response Status Code: " . $e->getStatusCode() . ' ';
        $message .= "Error Code: " . $e->getErrorCode() . " ";
        $message .= "Error Type: " . $e->getErrorType() . " ";
        $message .= "Request ID: " . $e->getRequestId() . " ";
        $message .= "ResponseHeaderMetadata: " . $e->getResponseHeaderMetadata() . " ";

        return $message;
    }
}
