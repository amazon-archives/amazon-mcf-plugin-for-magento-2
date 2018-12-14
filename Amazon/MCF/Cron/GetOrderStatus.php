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
class GetOrderStatus
{
    /**
     * This number needs to keep in mind the API throttling, currently burst
     * rate of 30 and restore 2 per second.
     *
     * http://docs.developer.amazonservices.com/en_US/fbaoutbound/FBAOutbound_CreateFulfillmentOrder.html
     */
    const NUM_ORDERS_TO_RESUBMIT = 20;

    const NUM_ORDER_RESUMIT_RETRYS = 5;

    /**
     * @var \Amazon\MCF\Helper\Data
     */
    private $helper;

    /**
     * @var \Amazon\MCF\Helper\Conversion
     */
    private $conversionHelper;

    /**
     * @var \Amazon\MCF\Model\Service\Outbound
     */
    private $outbound;

    /**
     * @var \Magento\Sales\Model\ResourceModel\Order\CollectionFactory
     */
    private $orderCollectionFactory;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var \Magento\Sales\Model\Service\InvoiceService
     */
    private $invoiceService;

    /**
     * @var \Magento\Framework\DB\Transaction
     */
    private $transaction;

    /**
     * @var \Magento\Sales\Api\OrderManagementInterface
     */
    private $orderManagement;

    /**
     * @var \Magento\Framework\App\ObjectManager
     */
    private $objectManager;

    /**
     * @var \Magento\Sales\Model\Order\Shipment\TrackFactory
     */
    private $trackFactory;

    /**
     * @var \Magento\Sales\Model\Order\Email\Sender\InvoiceSender
     */
    private $invoiceSender;

    /**
     * @var \Magento\Sales\Model\Order\Email\Sender\ShipmentSender
     */
    private $shipmentSender;

    /**
     * GetOrderStatus constructor
     *
     * @param \Amazon\MCF\Helper\Data                                    $helper
     * @param \Amazon\MCF\Helper\Conversion                              $conversionHelper
     * @param \Amazon\MCF\Model\Service\Outbound                         $outbound
     * @param \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderCollectionFactory
     * @param \Magento\Sales\Model\Service\InvoiceService                $invoiceService
     * @param \Magento\Sales\Model\Order\Email\Sender\InvoiceSender      $invoiceSender
     * @param \Magento\Sales\Api\OrderManagementInterface                $orderManagement
     * @param \Magento\Store\Model\StoreManagerInterface                 $storeManager
     * @param \Magento\Framework\DB\Transaction                          $transaction
     * @param \Magento\Sales\Model\Order\Shipment\TrackFactory           $trackFactory
     */
    public function __construct(
        \Amazon\MCF\Helper\Data $helper,
        \Amazon\MCF\Helper\Conversion $conversionHelper,
        \Amazon\MCF\Model\Service\Outbound $outbound,
        \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderCollectionFactory,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Sales\Model\Order\Email\Sender\InvoiceSender $invoiceSender,
        \Magento\Sales\Api\OrderManagementInterface $orderManagement,
        StoreManagerInterface $storeManager,
        \Magento\Framework\DB\Transaction $transaction,
        \Magento\Sales\Model\Order\Shipment\TrackFactory $trackFactory,
        \Magento\Sales\Model\Order\Email\Sender\ShipmentSender $shipmentSender
    ) {

        $this->helper = $helper;
        $this->conversionHelper = $conversionHelper;
        $this->outbound = $outbound;
        $this->invoiceService = $invoiceService;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->storeManager = $storeManager;
        $this->transaction = $transaction;
        $this->orderManagement = $orderManagement;
        $this->trackFactory = $trackFactory;
        $this->invoiceSender = $invoiceSender;
        $this->shipmentSender = $shipmentSender;

        $om = \Magento\Framework\App\ObjectManager::getInstance();
        $this->objectManager = $om;
    }

    /**
     * Cron callback for updating order status.
     */
    public function orderUpdate()
    {
        $this->helper->logOrder('Beginning Order Update.');
        $stores = $this->storeManager->getStores();

        $enabledStores = [];

        foreach ($stores as $store) {
            if ($store->isActive() && $this->helper->isEnabled($store->getId())) {
                $enabledStores[] = $store->getId();
            }
        }

        if ($enabledStores) {
            $ordersToProcess = null;

            $ordersToProcess = $this->orderCollectionFactory
                ->create()
                ->addFieldToSelect('*')
                ->addFieldToFilter(
                    'store_id',
                    [
                    'in' => [$enabledStores],
                    ]
                )
                ->addFieldToFilter(
                    'state',
                    [
                    'in' => [
                        \Magento\Sales\Model\Order::STATE_NEW,
                        \Magento\Sales\Model\Order::STATE_PROCESSING,
                    ],
                    ]
                )
                ->addFieldToFilter('fulfilled_by_amazon', true)
                ->addFieldToFilter(
                    'amazon_order_status',
                    [
                    'in' => [
                        $this->helper::ORDER_STATUS_RECEIVED,
                        $this->helper::ORDER_STATUS_PLANNING,
                        $this->helper::ORDER_STATUS_PROCESSING,
                    ],
                    ]
                );

            if (!empty($ordersToProcess) && $ordersToProcess->count()) {
                $this->helper->logOrder('Beginning Order Update for ' . $ordersToProcess->count() . ' orders.');

                foreach ($ordersToProcess as $order) {
                    $this->helper->logOrder('Updating order #' . $order->getIncrementId());

                    $result = $this->outbound->getFulfillmentOrder($order);
                    if ($result) {
                        $fulfillmentOrderResult = $result->getGetFulfillmentOrderResult();

                        // Amazon Statuses: RECEIVED / INVALID / PLANNING / PROCESSING / CANCELLED / COMPLETE
                        // / COMPLETE_PARTIALLED / UNFULFILLABLE
                        $amazonStatus = $fulfillmentOrderResult->getFulfillmentOrder()
                            ->getFulfillmentOrderStatus();

                        $amazonOrderId = $fulfillmentOrderResult->getFulfillmentOrder()
                            ->getDisplayableOrderId();

                        $id = $order->getIncrementId();
                        if ($order->getIncrementId() == $amazonOrderId) {
                            $this->helper->logOrder(
                                'Status of order #' . $order->getIncrementId() . ': ' . $amazonStatus
                            );

                            if ($amazonStatus) {
                                switch ($amazonStatus) {
                                    case 'COMPLETE':
                                    case 'COMPLETE_PARTIALLED':
                                        $this->magentoOrderUpdate($order, $fulfillmentOrderResult);
                                        break;
                                    case 'INVALID':
                                    case 'CANCELLED':
                                    case 'UNFULFILLABLE':
                                        $this->cancelFBAShipment($order, $fulfillmentOrderResult);
                                        break;
                                }
                            }
                        }
                    }
                }

                $this->helper->logOrder(__('Get Order status called. Orders to process: ') . $ordersToProcess->count());
            } else {
                $this->helper->logOrder(__('Get Order status called. No orders to process'));
            }
        }
    }

    /**
     * Handles cancelation of items if order is canceled via seller central or
     * an item can't be fulfilled via FBA for some reason.
     *
     * @param  \Magento\Sales\Model\Order                             $order
     * @param  \FBAOutboundServiceMWS_Model_GetFulfillmentOrderResult $fulfillmentResult
     * @param  $amazonStatus
     * @throws \Exception
     */
    private function cancelFBAShipment(
        \Magento\Sales\Model\Order $order,
        \FBAOutboundServiceMWS_Model_GetFulfillmentOrderResult $fulfillmentResult,
        $amazonStatus
    ) {

        if ($order->canCancel()) {
            $shipment = $fulfillmentResult->getFulfillmentOrderItem();
            $skus = [];
            // Get skus from cancelled order
            foreach ($shipment->getmember() as $amazonShipment) {
                $skus[] = $amazonShipment->getSellerSKU();
            }

            // if there are skus, match them to products in the order. We want to cancel specific
            // items not the entire order.
            if ($skus) {
                $canceledSku = [];
                foreach ($order->getAllItems() as $item) {
                    $product = $item->getProduct();
                    if (in_array($product->getSku(), $skus) || in_array($product->getAmazonMcfMerchantSku(), $skus)) {
                        // check to make sure the item hasn't already been canceled
                        if ($item->getQtyOrdered() != $item->getQtyCanceled()) {
                            $qty = $item->getQtyOrdered();

                            $item->setQtyCanceled($qty);
                            $item->save();
                            $canceledSku[] = $product->getSku();
                        }
                    }
                }

                // If we have canceled items, add a comment.
                if ($canceledSku) {
                    $this->helper->logOrder(
                        'FBA order canceled - items set to canceled with SKUs: ' . implode(", ", $canceledSku)
                    );
                    $order->addStatusHistoryComment(
                        "FBA items with Magento SKUs: " . implode(", ", $canceledSku)
                        . " are unable to be fulfilled. Check your seller central account for more information."
                    );
                    $order->save();
                }
            }
        }
    }

    /**
     * This invoice and ships the order
     *
     * @param \Magento\Sales\Model\Order                    $order
     * @param \FBAOutboundServiceMWS_Model_FulfillmentOrder $fulfillmentOrderResult
     */
    private function magentoOrderUpdate(
        \Magento\Sales\Model\Order $order,
        \FBAOutboundServiceMWS_Model_GetFulfillmentOrderResult $fulfillmentOrderResult
    ) {
        $this->invoiceOrder($order, $fulfillmentOrderResult);
        $this->createShipment($order, $fulfillmentOrderResult);
    }

    /**
     * Create invoice for shipped items
     *
     * @param \Magento\Sales\Model\Order                    $order
     * @param \FBAOutboundServiceMWS_Model_FulfillmentOrder $fulfillmentOrder
     */
    private function invoiceOrder(
        \Magento\Sales\Model\Order $order,
        \FBAOutboundServiceMWS_Model_GetFulfillmentOrderResult $fulfillmentOrderResult
    ) {
        if ($order->canInvoice()) {
            $quantities = $order->getTotalQtyOrdered();
            $values = $order->getShippingInclTax();
            $shippingAmount = $values / $quantities;
            $shipments = [];
            // Get skus from cancelled order
            foreach ($fulfillmentOrderResult->getFulfillmentShipment()
                ->getmember() as $amazonShipment) {
                foreach ($amazonShipment->getFulfillmentShipmentItem()
                    ->getMember() as $item) {
                    $shipments[$item->getSellerSKU()] = $item->getQuantity();
                }
            }
            // get item id of FBA item that shipped.
            foreach ($order->getAllItems() as $item) {
                $product = $item->getProduct();
                if (isset($shipments[$product->getSku()])) {
                    $invoice = $this->invoiceService->prepareInvoice(
                        $order,
                        [$item->getId() => $shipments[$product->getSku()]]
                    );
                    $subTotal = $item->getPrice();
                    $baseSubtotal = $item->getBasePrice();
                    $grandTotal = $item->getPrice() + $item->getTaxAmount();
                    $baseGrandTotal = $item->getBasePrice() + $item->getBaseTaxAmount();

                    $invoice->setShippingAmount($shippingAmount);
                    $invoice->setSubtotal($subTotal);
                    $invoice->setBaseSubtotal($baseSubtotal);
                    $invoice->setGrandTotal($grandTotal);
                    $invoice->setBaseGrandTotal($baseGrandTotal);
                    $invoice->register();

                    $transactionSave = $this->transaction->addObject(
                        $invoice
                    )->addObject(
                        $invoice->getOrder()
                    );
                    $transactionSave->save();
                    $this->invoiceSender->send($invoice);
                    //send notification code
                    $order->addStatusHistoryComment(
                        __('Notified customer about invoice #%1.', $invoice->getId())
                    )
                        ->setIsCustomerNotified(true)
                        ->save();
                }
            }
        }
    }

    /**
     * Create shipment
     *
     * @param \Magento\Sales\Model\Order                    $order
     * @param \FBAOutboundServiceMWS_Model_FulfillmentOrder $fulfillmentOrder
     */
    private function createShipment(
        \Magento\Sales\Model\Order $order,
        \FBAOutboundServiceMWS_Model_GetFulfillmentOrderResult $fulfillmentOrder
    ) {
        if ($order->canShip()) {
            $shipmentItems = [];
            $shipments = [];

            // group shipments by package number
            foreach ($fulfillmentOrder->getFulfillmentShipment()
                ->getmember() as $fulfillmentShipment) {
                foreach ($fulfillmentShipment->getFulfillmentShipmentItem()
                    ->getmember() as $details) {
                    if ($details) {
                        $shipments[$details->getPackageNumber()][] = [
                            'sellerSku' => $details->getSellerSKU(),
                            'quantity' => $details->getQuantity(),
                        ];
                    }
                }
            }

            if ($shipments) {
                $packages = $this->getPackagesFromFulfillmentOrder($fulfillmentOrder);

                // match each package with tracking information
                foreach ($packages as $package) {
                    if (isset($shipments[$package->getPackageNumber()])) {
                        $shipments[$package->getPackageNumber()]['tracking'] = [
                            'carrierCode' => $this->conversionHelper->getCarrierCodeFromPackage($package),
                            'title' => $this->conversionHelper->getCarrierTitleFromPackage($package),
                        ];
                    }
                }

                // match order items with each shipment/package
                // so that 1 shipment can have 1..n orders based on packaging information
                foreach ($shipments as $packageNumber => $data) {
                    $convertOrder = $this->objectManager->create('Magento\Sales\Model\Convert\Order');
                    $shipment = $convertOrder->toShipment($order);

                    foreach ($order->getAllItems() as $orderItem) {
                        $product = $orderItem->getProduct();

                        foreach ($data as $index => $item) {
                            if (isset($item['sellerSku'])
                                && (($item['sellerSku'] == $product->getSku())
                                || $item['sellerSku'] == $product->getAamazonMcfMerchantSku())
                            ) {
                                if ($orderItem->getQtyToShip() && !$orderItem->getIsVirtual()) {
                                    $shipmentItem = $convertOrder->itemToShipmentItem($orderItem)
                                        ->setQty($item['quantity']);

                                    // Add shipment item to shipment
                                    $shipment->addItem($shipmentItem);
                                }
                            }
                        }
                    }

                    if (isset($shipments[$packageNumber]['tracking'])) {
                        $data = [
                            'carrier_code' => $shipments[$packageNumber]['tracking']['carrierCode'],
                            'title' => $shipments[$packageNumber]['tracking']['title'],
                            'number' => $packageNumber,
                        ];

                        $track = $this->trackFactory->create()
                            ->addData($data);
                        $shipment->addTrack($track);
                    }

                    try {
                        $shipment->register();
                        $shipment->getOrder()->setIsInProcess(true);
                        // Save shipment
                        $shipment->save();
                        // Send shipment email
                        $this->shipmentSender->send($shipment);
                        // Save order
                        $shipment->getOrder()->save();
                    } catch (\Exception $e) {
                        $this->helper->logOrder(__($e->getMessage()));
                    }
                }
            }
        } else {
            $this->helper->logOrder(
                'Unable to create Amazon FBA Shipment for order: ' . $order->getRealOrderId() . '.'
            );
        }
    }

    /**
     * @param \FBAOutboundServiceMWS_Model_FulfillmentOrder $order
     *
     * @return array
     */
    private function getPackagesFromFulfillmentOrder(
        \FBAOutboundServiceMWS_Model_GetFulfillmentOrderResult $order
    ) {
        /**
         * @var FBAOutboundServiceMWS_Model_FulfillmentShipmentList $shipments
         */
        $shipments = $order->getFulfillmentShipment();
        $packages = [];

        if (!empty($shipments)) {
            /**
             * @var FBAOutboundServiceMWS_Model_FulfillmentShipment $amazonShipment
             */
            foreach ($shipments->getmember() as $amazonShipment) {
                /**
                 * @var FBAOutboundServiceMWS_Model_FulfillmentShipmentPackageList $package
                 */
                $packages = array_merge(
                    $packages,
                    $amazonShipment->getFulfillmentShipmentPackage()
                        ->getmember()
                );
            }
        }

        return $packages;
    }

    /**
     * Resubmits order to Amazon in event it did not go thorugh syncronously.
     */
    public function resubmitOrdersToAmazon()
    {
        $stores = $this->storeManager->getStores();
        $enabledStores = [];

        foreach ($stores as $store) {
            if ($store->isActive() && $this->helper->isEnabled($store->getId())) {
                $enabledStores[] = $store->getId();
            }
        }

        if ($enabledStores) {
            $ordersToProcess = $this->orderCollectionFactory
                ->create()
                ->addFieldToSelect('*')
                ->addFieldToFilter(
                    'store_id',
                    [
                        'in' => [$enabledStores],
                    ]
                )
                ->addFieldToFilter(
                    'state',
                    [
                        'in' => [
                            \Magento\Sales\Model\Order::STATE_NEW,
                            \Magento\Sales\Model\Order::STATE_PROCESSING,
                        ],
                    ]
                )
                ->addFieldToFilter('fulfilled_by_amazon', true)
                ->addFieldToFilter(
                    'amazon_order_status',
                    [
                        'in' => [
                            $this->helper::ORDER_STATUS_NEW,
                            $this->helper::ORDER_STATUS_ATTEMPTED,
                        ],
                    ]
                )
                ->setPageSize(self::NUM_ORDERS_TO_RESUBMIT)
                ->setCurPage(1);

            if ($ordersToProcess->count()) {
                foreach ($ordersToProcess as $order) {
                    $this->helper->logOrder('Retrying submission of order #' . $order->getIncrementId());
                    $currentAttempt = $order->getAmazonSubmissionCount() + 1;
                    /**
                     * @var \FBAOutboundServiceMWS_Model_CreateFulfillmentOrderResponse $result
                     */
                    $result = $this->outbound->createFulfillmentOrder($order);
                    $responseMetadata = $result ? $result->getResponseMetadata() : null;

                    if (!empty($result) && !empty($responseMetadata)) {
                        $order->setAmazonOrderStatus($this->helper::ORDER_STATUS_RECEIVED);
                    } elseif ($currentAttempt >= self::NUM_ORDER_RESUMIT_RETRYS) {
                        $order->setAmazonOrderStatus($this->helper::ORDER_STATUS_FAIL);
                        $this->helper->logOrder(
                            'Giving up on order #' . $order->getIncrementId() . "after $currentAttempt tries."
                        );
                    } else {
                        $order->setAmazonSubmissionCount($currentAttempt);
                    }

                    $order->save();
                }
            }
        }
    }
}
