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

namespace Amazon\MCF\Cron;

use Amazon\MCF\Helper\Data;
use Amazon\MCF\Helper\Conversion;
use Amazon\MCF\Model\Service\Outbound;
use Magento\Store\Model\StoreManagerInterface;


/**
 * Cron callback class that handles order and shipment via Amazon MCF API
 *
 * Class GetOrderStatus
 *
 * @package Amazon\MCF\Cron
 */
class GetOrderStatus {

    /**
     * This number needs to keep in mind the API throttling, currently burst
     * rate of 30 and restore 2 per second.
     *
     * http://docs.developer.amazonservices.com/en_US/fba_outbound/FBAOutbound_CreateFulfillmentOrder.html
     */
    const NUM_ORDERS_TO_RESUBMIT = 20;

    const NUM_ORDER_RESUMIT_RETRYS = 5;

    /**
     * @var \Amazon\MCF\Helper\Data
     */
    protected $_helper;

    /**
     * @var \Amazon\MCF\Helper\Conversion
     */
    protected $_conversionHelper;

    /**
     * @var \Amazon\MCF\Model\Service\Outbound
     */
    protected $_outbound;

    /**
     * @var \Magento\Sales\Model\ResourceModel\Order\CollectionFactory
     */
    protected $_orderCollectionFactory;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $_storeManager;

    /**
     * @var \Magento\Sales\Model\Service\InvoiceService
     */
    protected $_invoiceService;

    /**
     * @var \Magento\Framework\DB\Transaction
     */
    protected $_transaction;

    /**
     * @var \Magento\Sales\Api\OrderManagementInterface
     */
    protected $_orderManagement;

    /**
     * @var \Magento\Framework\App\ObjectManager
     */
    protected $_objectManager;

    /**
     * @var \Magento\Sales\Model\Order\Shipment\TrackFactory
     */
    protected $_trackFactory;

    /**
     * GetOrderStatus constructor.
     *
     * @param \Amazon\MCF\Helper\Data $helper
     * @param \Amazon\MCF\Helper\Conversion $conversionHelper
     * @param \Amazon\MCF\Model\Service\Outbound $outbound
     * @param \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderCollectionFactory
     * @param \Magento\Sales\Model\Service\InvoiceService $invoiceService
     * @param \Magento\Sales\Api\OrderManagementInterface $orderManagement
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Framework\DB\Transaction $transaction
     * @param \Magento\Sales\Model\Order\Shipment\TrackFactory $trackFactory
     */
    public function __construct(
        Data $helper, Conversion $conversionHelper,
        Outbound $outbound,
        \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderCollectionFactory,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Sales\Api\OrderManagementInterface $orderManagement,
        StoreManagerInterface $storeManager,
        \Magento\Framework\DB\Transaction $transaction,
        \Magento\Sales\Model\Order\Shipment\TrackFactory $trackFactory
    ) {

        $this->_helper = $helper;
        $this->_conversionHelper = $conversionHelper;
        $this->_outbound = $outbound;
        $this->_invoiceService = $invoiceService;
        $this->_orderCollectionFactory = $orderCollectionFactory;
        $this->_storeManager = $storeManager;
        $this->_transaction = $transaction;
        $this->_orderManagement = $orderManagement;
        $this->_trackFactory = $trackFactory;

        $om = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_objectManager = $om;
    }

    /**
     * Cron callback for updating order status.
     */
    public function orderUpdate() {
        $this->_helper->logOrder('Beginning Order Update.');
        $stores = $this->_storeManager->getStores();

        $enabledStores = [];

        foreach ($stores as $store) {
            if ($store->isActive() && $this->_helper->isEnabled($store->getId())) {
                $enabledStores[] = $store->getId();
            }
        }

        if ($enabledStores) {
            $ordersToProcess = NULL;

            $ordersToProcess = $this->_orderCollectionFactory
                ->create()
                ->addFieldToSelect('*')
                ->addFieldToFilter('store_id', [
                    'in' => [$enabledStores],
                ])
                ->addFieldToFilter('state', [
                    'in' => [
                        \Magento\Sales\Model\Order::STATE_NEW,
                        \Magento\Sales\Model\Order::STATE_PROCESSING,
                    ],
                ])
                ->addFieldToFilter('fulfilled_by_amazon', TRUE)
                ->addFieldToFilter('amazon_order_status', [
                    'in' => [
                        $this->_helper::ORDER_STATUS_RECEIVED,
                        $this->_helper::ORDER_STATUS_PLANNING,
                        $this->_helper::ORDER_STATUS_PROCESSING,
                    ],
                ]);


            if ($ordersToProcess->count()) {
                $this->_helper->logOrder('Beginning Order Update for ' . $ordersToProcess->count() . ' orders.');


                foreach ($ordersToProcess as $order) {

                    $this->_helper->logOrder('Updating order #' . $order->getIncrementId());

                    $result = $this->_outbound->getFulfillmentOrder($order);
                    if ($result) {
                        $fulfillmentOrderResult = $result->getGetFulfillmentOrderResult();

                        // Amazon Statuses: RECEIVED / INVALID / PLANNING / PROCESSING / CANCELLED / COMPLETE / COMPLETE_PARTIALLED / UNFULFILLABLE
                        $amazonStatus = $fulfillmentOrderResult->getFulfillmentOrder()
                            ->getFulfillmentOrderStatus();

                        $this->_helper->logOrder('Status of order #' . $order->getIncrementId() . ': ' . $amazonStatus);

                        if ($amazonStatus) {
                            switch ($amazonStatus) {
                                case 'COMPLETE':
                                case 'COMPLETE_PARTIALLED':
                                    $this->magentoOrderUpdate($order, $fulfillmentOrderResult, $amazonStatus);
                                    break;
                                case 'INVALID':
                                case 'CANCELLED':
                                case 'UNFULFILLABLE':
                                    $order->setAmazonOrderStatus(strtolower($amazonStatus));
                                    $order->save();
                                    $order->setSkipAmazonCancel(true);
                                    $this->_orderManagement->cancel($order->getEntityId());
                                    break;
                                default:
                                    $order->setAmazonOrderStatus($amazonStatus);
                                    $order->save();
                                    break;
                            }

                        }
                    }
                }
            }
        }

        $this->_helper->logOrder('Get Order status called. Orders to process: ' . $ordersToProcess->count());
    }


    /**
     * This invoice and ships the order
     *
     * @param \Magento\Sales\Model\Order $order
     * @param \FBAOutboundServiceMWS_Model_FulfillmentOrder $fulfillmentOrderResult
     */
    protected function magentoOrderUpdate(\Magento\Sales\Model\Order $order, \FBAOutboundServiceMWS_Model_GetFulfillmentOrderResult $fulfillmentOrderResult, $amazonStatus) {

        $fulfillmentOrder = $fulfillmentOrderResult->getFulfillmentOrder();
        $this->invoiceOrder($order, $fulfillmentOrder, $amazonStatus);
        $this->createShipment($order, $fulfillmentOrderResult);

    }

    /**
     * Create invoice for shipped items
     *
     * @param \Magento\Sales\Model\Order $order
     * @param \FBAOutboundServiceMWS_Model_FulfillmentOrder $fulfillmentOrder
     */
    protected function invoiceOrder(\Magento\Sales\Model\Order $order, \FBAOutboundServiceMWS_Model_FulfillmentOrder $fulfillmentOrder, $amazonStatus) {

        if ($order->canInvoice()) {

            $invoice = $this->_invoiceService->prepareInvoice($order);
            $invoice->register();
            $invoice->setAmazonOrderStatus(strtolower($amazonStatus));

            $transactionSave = $this->_transaction->addObject($invoice)
                ->addObject($invoice->getOrder());
            $transactionSave->save();

            $order->setAmazonOrderStatus(strtolower($amazonStatus));

            $order->addStatusHistoryComment(
                __('Notified customer about invoice #%1.', $invoice->getId())
            )
                ->setIsCustomerNotified(TRUE)
                ->save();
        }
    }

    /**
     * Create shipment
     *
     * @param \Magento\Sales\Model\Order $order
     * @param \FBAOutboundServiceMWS_Model_FulfillmentOrder $fulfillmentOrder
     */
    protected function createShipment(\Magento\Sales\Model\Order $order, \FBAOutboundServiceMWS_Model_GetFulfillmentOrderResult $fulfillmentOrder) {
        if ($order->canShip()) {
            $packages = $this->getPackagesFromFulfillmentOrder($fulfillmentOrder);

            foreach ($packages as $package) {

                $convertOrder = $this->_objectManager->create('Magento\Sales\Model\Convert\Order');
                $shipment = $convertOrder->toShipment($order);

                foreach ($order->getAllItems() as $orderItem) {
                    if ($orderItem->getQtyToShip() && !$orderItem->getIsVirtual()) {

                        $qtyShipped = $orderItem->getQtyToShip();

                        // Create shipment item with qty
                        $shipmentItem = $convertOrder->itemToShipmentItem($orderItem)
                            ->setQty($qtyShipped);

                        // Add shipment item to shipment
                        $shipment->addItem($shipmentItem);
                    }
                }

                $shipment->register();

                $shipment->getOrder()->setIsInProcess(TRUE);

                $trackData = [
                    'carrier_code' => $this->_conversionHelper->getCarrierCodeFromPackage($package),
                    'title' => $this->_conversionHelper->getCarrierTitleFromPackage($package),
                    'number' => $package->getTrackingNumber(),
                ];

                $track = $this->_trackFactory->create()->addData($trackData);

                try {
                    // Save created shipment and order
                    $shipment->save();
                    $shipment->getOrder()->save();
                    $shipment->addTrack($track)->save();

                    // Send email
                    $this->_objectManager->create('Magento\Shipping\Model\ShipmentNotifier')
                        ->notify($shipment);

                    $shipment->save();
                } catch (\Exception $e) {
                    $this->_helper->logOrder(__($e->getMessage()));

                }

                $transactionSave = $this->_transaction->addObject($shipment)
                    ->addObject($shipment->getOrder());
                $transactionSave->save();


            }
        }
        else {
            $this->_helper->logOrder('Unable to create Amazon FBA Shipment for order: ' . $order->getRealOrderId() . '.');
        }
    }

    /**
     * @param \FBAOutboundServiceMWS_Model_FulfillmentOrder $order
     *
     * @return array
     */
    protected function getPackagesFromFulfillmentOrder(\FBAOutboundServiceMWS_Model_GetFulfillmentOrderResult $order) {
        /** @var FBAOutboundServiceMWS_Model_FulfillmentShipmentList $shipments */
        $shipments = $order->getFulfillmentShipment();
        $packages = [];

        // @TODO: key by items in the shipments?
        if (!empty($shipments)) {
            /** @var FBAOutboundServiceMWS_Model_FulfillmentShipment $amazonShipment */
            foreach ($shipments->getmember() as $amazonShipment) {
                /** @var FBAOutboundServiceMWS_Model_FulfillmentShipmentPackageList $package */
                $packages = array_merge($packages, $amazonShipment->getFulfillmentShipmentPackage()
                    ->getmember());
            }
        }

        return $packages;
    }

    public function resubmitOrdersToAmazon() {
        $stores = $this->_storeManager->getStores();
        $enabledStores = [];

        foreach ($stores as $store) {
            if ($store->isActive() && $this->_helper->isEnabled($store->getId())) {
                $enabledStores[] = $store->getId();
            }
        }

        $ordersToProcess = $this->_orderCollectionFactory
            ->create()
            ->addFieldToSelect('*')
            ->addFieldToFilter('store_id', [
                'in' => [$enabledStores],
            ])
            ->addFieldToFilter('state', [
                'in' => [
                    \Magento\Sales\Model\Order::STATE_NEW,
                    \Magento\Sales\Model\Order::STATE_PROCESSING,
                ],
            ])
            ->addFieldToFilter('fulfilled_by_amazon', TRUE)
            ->addFieldToFilter('amazon_order_status', [
                'in' => [
                    $this->_helper::ORDER_STATUS_NEW,
                    $this->_helper::ORDER_STATUS_ATTEMPTED,
                ],
            ])
            ->setPageSize(self::NUM_ORDERS_TO_RESUBMIT)
            ->setCurPage(1);

        if ($ordersToProcess->count()) {
            foreach ($ordersToProcess as $order) {
                $this->_helper->logOrder('Retrying submission of order #' . $order->getIncrementId());
                $currentAttempt = $order->getAmazonSubmissionCount() + 1;
                /** @var \FBAOutboundServiceMWS_Model_CreateFulfillmentOrderResponse $result */
                $result = $this->_outbound->createFulfillmentOrder($order);
                $responseMetadata = $result->getResponseMetadata();

                if (!empty($result) && !empty($responseMetadata)) {
                    $order->setAmazonOrderStatus($this->_helper::ORDER_STATUS_RECEIVED);
                }
                elseif ($currentAttempt >= self::NUM_ORDER_RESUMIT_RETRYS) {
                    $order->setAmazonOrderStatus($this->_helper::ORDER_STATUS_FAIL);
                    $this->_helper->logOrder('Giving up on order #' . $order->getIncrementId() . "after $currentAttempt tries.");
                }
                else {
                    $order->setAmazonSubmissionCount($currentAttempt);
                }

                $order->save();
            }
        }
    }

}