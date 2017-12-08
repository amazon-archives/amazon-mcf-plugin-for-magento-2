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


namespace Amazon\MCF\Model\Observer;

use Amazon\MCF\Helper\Data;
use Amazon\MCF\Model\Service\Outbound;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Listens for order submission and also ensures it is submitted to Amazon MCF
 *
 * Class SubmitOrderObserver
 *
 * @package Amazon\MCF\Model\Observer
 */
class SubmitOrderObserver implements ObserverInterface {

    /**
     * @var \Amazon\MCF\Helper\Data
     */
    protected $_helper;

    /**
     * @var \Amazon\MCF\Model\Service\Outbound
     */
    protected $_outbound;

    /**
     * ShippingObserver constructor.
     *
     * @param \Amazon\MCF\Helper\Data $helper
     * @param \Amazon\MCF\Model\Service\Outbound $outbound
     */
    public function __construct(Data $helper, Outbound $outbound) {
        $this->_helper = $helper;
        $this->_outbound = $outbound;
    }

    /**
     * @param \Magento\Framework\Event\Observer $observer
     */
    public function execute(Observer $observer) {

        $order = $observer->getData('order');

        // Do we have any items to submit to Amazon?
        $amazonItemInOrder = false;

        foreach ($order->getAllItems() as $item) {
            if ($item->getProduct()->getAmazonMcfAsinEnabled()) {
                $order->setFulfilledByAmazon(true);
                $amazonItemInOrder = true;
                break;
            }
        }

        if (!$this->_helper->isEnabled() || !$amazonItemInOrder) {
            return;
        }

        $response = $this->_outbound->createFulfillmentOrder($order);

        if (!empty($response)) {
            $order->setAmazonOrderStatus($this->_helper::ORDER_STATUS_RECEIVED);
            $order->setAmazonSubmissionCount(0);
        } else {
            $order->setAmazonOrderStatus($this->_helper::ORDER_STATUS_ATTEMPTED);
            $order->setAmazonSubmissionCount(1);
        }
    }

}