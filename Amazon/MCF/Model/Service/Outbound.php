<?php
/*
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

namespace Amazon\MCF\Model\Service;

use Amazon\MCF\Helper\Data;
use Amazon\MCF\Helper\Conversion;
use Magento\Framework\Logger\Monolog;

/**
 * Handles calls to Amazon MCF Fulfillment Outbound
 *
 * Class Outbound
 *
 * @package Amazon\MCF\Model\Service
 */
class Outbound extends MCFAbstract {

    const SERVICE_NAME = '/FulfillmentOutboundShipment/';

    const SERVICE_CLASS = 'FBAOutboundServiceMWS';

    /**
     * @var \Amazon\MCF\Helper\Data
     */
    protected $_helper;

    /**
     * @var \Amazon\MCF\Helper\Conversion
     */
    protected $_conversionHelper;

    /**
     * @var \Magento\Framework\Notification\NotifierPool
     */
    protected $_notifierPool;

    /**
     * Outbound constructor.
     *
     * @param \Amazon\MCF\Helper\Data $helper
     * @param \Amazon\MCF\Helper\Conversion $conversionHelper
     * @param \Magento\Framework\Notification\NotifierPool $notifierPool
     */
    public function __construct(\Amazon\MCF\Helper\Data $helper,
                                \Amazon\MCF\Helper\Conversion $conversionHelper,
                                \Magento\Framework\Notification\NotifierPool $notifierPool) {

        parent::__construct($helper);

        $this->_helper = $helper;
        $this->_conversionHelper = $conversionHelper;
        $this->_notifierPool = $notifierPool;

        require_once($this->getModulePath() . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'Amazon' . DIRECTORY_SEPARATOR . 'FBAOutboundServiceMWS' . DIRECTORY_SEPARATOR . 'Exception.php');
        require_once($this->getModulePath() . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'Amazon' . DIRECTORY_SEPARATOR . 'FBAOutboundServiceMWS' . DIRECTORY_SEPARATOR . 'Client.php');
        require_once($this->getModulePath() . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'Amazon' . DIRECTORY_SEPARATOR . 'FBAOutboundServiceMWS' . DIRECTORY_SEPARATOR . 'Mock.php');
    }

    /**
     * Gets a shipping preview from Amazon MCF API
     *
     * @param $quote
     *
     * @return mixed
     */
    public function getFulfillmentPreview($address, $items) {

        $client = $this->getClient();

        $request = [
            'SellerId' => $this->_helper->getSellerId(),
            'Address' => $address,
            'Items' => $items
        ];

        try {
            $preview = $client->getFulfillmentPreview($request);
        } catch (\FBAOutboundServiceMWS_Exception $e) {
            $this->_helper->logOrder($this->_getErrorDebugMessage($e));
            $preview = NULL;
        }

        return $preview;
    }


    /**
     * Returns rate information for a single item for product page estimate
     *
     * @param $address
     * @param $items
     *
     * @return array|null
     */
    public function getProductEstimate($address, $items) {
        $fulfillmentPreview = $this->getFulfillmentPreview($address, $items);
        $rates = [];

        if ($fulfillmentPreview) {
            $previews = $fulfillmentPreview->getGetFulfillmentPreviewResult()
                ->getFulfillmentPreviews()
                ->getmember();

            $dates = [];

            /** @var FBAOutboundServiceMWS_Model_FulfillmentPreview $preview */
            $counter = 0;
            foreach ($previews as $preview) {
                $shipDate = 0;
                if ($preview->getIsFulfillable() != 'false') {
                    $title = $preview->getShippingSpeedCategory();
                    $sdpreview = $preview->getFulfillmentPreviewShipments()
                        ->getmember();

                    foreach ($sdpreview as $sdates) {
                        $latest = '';
                        $earliest = '';
                        $time = 0;
                        $shippingFee = 0;
                        $counter++;


                        if (!empty($preview->getEstimatedFees()->getmember())) {
                            foreach ($preview->getEstimatedFees()
                                         ->getmember() as $fee) {
                                $shippingFee = $fee->getAmount()
                                    ->getValue(); // just returning the first fee amount for now
                                break;
                            }
                        }

                        if (!empty($sdates->getLatestArrivalDate())) {
                            $latest = $sdates->getLatestArrivalDate();

                            $time = strtotime($sdates->getLatestArrivalDate());
                        }

                        if (!empty($sdates->getEarliestArrivalDate())) {
                            $earliest = $sdates->getEarliestArrivalDate();
                            $time = strtotime($sdates->getEarliestArrivalDate());
                        }
                    }
                    // force sort on shortest time period - added counter so
                    // that if the timestamp is the same it will not overwrite index
                    $dates[$time + $counter] = [
                        'type' => $title,
                        'earliest' => $earliest,
                        'latest' => $latest,
                        'cost' => $shippingFee,
                    ];
                }
            }

            if ($dates) {
                // sort and present array in format that works cleanly with JSON
                ksort($dates);
                foreach ($dates as $time => $data) {
                    $rates[] = $data;
                }
            }
        }

        return $rates;
    }

    /**
     * Creates request array and submits order to Amazon MCF for fulfillment.
     *
     * @param \Magento\Sales\Model\Order $order
     *
     * @return null|mixed
     */
    public function createFulfillmentOrder(\Magento\Sales\Model\Order $order) {
        $client = $this->getClient();

        $shipping = $this->_conversionHelper->getShippingSpeed($order->getShippingMethod());
        $address = $this->_conversionHelper->getAmazonAddressArray($order->getShippingAddress());
        $items = $this->_conversionHelper->getAmazonItemsArrayFromRateRequest($order->getAllItems());
        $timestamp = date('Y-m-d\TH:i:s.Z\Z');


        $request = [
            'SellerId' => $this->_helper->getSellerId(),
            'FulfillmentPolicy' => 'FillOrKill',
            'DestinationAddress' => $address,
            'SellerFulfillmentOrderId' => $order->getIncrementId(),
            'DisplayableOrderId' => $order->getIncrementId(),
            'DisplayableOrderDateTime' => $timestamp,
            'DisplayableOrderComment' => $this->_helper->getPackingSlipComment(),
            'ShippingSpeedCategory' => $shipping,
            'Items' => $items
        ];

        if ($this->_helper->sendAmazonShipConfirmation($order->getStoreId())) {
            $request['NotificationEmailList'] = ['member' => [$order->getCustomerEmail()]];
        }

        $response = NULL;

        // Attempt to create fulfillment order via Amazon MCF API
        try {
            $response = $client->createFulfillmentOrder($request);
        } catch (\FBAOutboundServiceMWS_Exception $e) {
            $this->_helper->logOrder($this->_getErrorDebugMessage($e));
            $response = NULL;
            $this->_notifierPool->addNotice('Amazon Multi-Channel Fulfillment', 'Unable to create a Fulfillment Order for order: '.$order->getIncrementId().'.');
        }

        return $response;
    }

    /**
     * Gets data about order via Amazon API
     *
     * @param \Magento\Sales\Model\Order $order
     *
     * @return null|mixed
     */
    public function getFulfillmentOrder(\Magento\Sales\Model\Order $order) {

        $client = $this->getClient();

        $request = $this->getRequest(
            [
                'SellerFulfillmentOrderId' => $order->getIncrementId(),
            ],
            $order->getStoreId()
        );

        try {
            $response = $client->getFulfillmentOrder($request);
        } catch (\FBAOutboundServiceMWS_Exception $e) {
            $this->_helper->logOrder($this->_getErrorDebugMessage($e));
            $response = NULL;
            $this->_notifierPool->addNotice('Amazon Multi-Channel Fulfillment', 'Unable to retrieve Amazon FBA data for order: '.$order->getIncrementId().'.');

        }

        return $response;
    }

    /**
     * Attempts to cancel order on Amazon seller central if it exists.
     *
     * @param \Magento\Sales\Model\Order $order
     *
     * @return null|mixed
     */
    public function cancelFulfillmentOrder(\Magento\Sales\Model\Order $order) {
        $response = NULL;

        $client = $this->getClient();

        $id = $order->getIncrementId();

        $request = [
            'SellerId' => $this->_helper->getSellerId(),
            'SellerFulfillmentOrderId' => $id
        ];

        // first see if this order exists on Amazon MCF
        try {
            $response = $client->getFulfillmentOrder($request);
        } catch (\FBAOutboundServiceMWS_Exception $e) {
            $this->_helper->logOrder($this->_getErrorDebugMessage($e));
            $response = NULL;
            $this->_notifierPool->addNotice('Amazon Multi-Channel Fulfillment', 'Fulfillment Order for order: '.$order->getIncrementId().' does not exist.');

        }

        // If it exists, cancel it.
        if ($response) {

            try {
                $response = $client->cancelFulfillmentOrder($request);
            } catch (\FBAOutboundServiceMWS_Exception $e) {
                $this->_helper->logOrder($this->_getErrorDebugMessage($e));
                $response = NULL;
                $this->_notifierPool->addNotice('Amazon Multi-Channel Fulfillment', 'Unable to cancel Fulfillment Order for order: '.$order->getIncrementId().'.');

            }
        }

        return $response;
    }

    /**
     * Extracts error message and preps it for use with logger
     *
     * @param FBAOutboundServiceMWS_Exception $e
     *
     * @return string
     */
    private function _getErrorDebugMessage(\FBAOutboundServiceMWS_Exception $e) {

        $message = "Caught Exception: " . $e->getMessage() . ' ';
        $message .= "Response Status Code: " . $e->getStatusCode() . ' ';
        $message .= "Error Code: " . $e->getErrorCode() . " ";
        $message .= "Error Type: " . $e->getErrorType() . " ";
        $message .= "Request ID: " . $e->getRequestId() . " ";
        $message .= "ResponseHeaderMetadata: " . $e->getResponseHeaderMetadata() . " ";

        return $message;
    }

}