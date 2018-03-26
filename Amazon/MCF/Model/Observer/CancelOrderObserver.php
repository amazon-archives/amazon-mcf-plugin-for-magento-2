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

namespace Amazon\MCF\Model\Observer;

use Magento\Framework\Event\ObserverInterface;

/**
 * Listens for order cancellation and ensures order is also canceled on Amazon
 * MCF side.
 *
 * Class CancelOrderObserver
 *
 * @package Amazon\MCF\Model\Observer
 */
class CancelOrderObserver implements ObserverInterface
{

    /**
     * @var \Amazon\MCF\Helper\Data
     */
    private $helper;

    /**
     * @var \Amazon\MCF\Model\Service\Outbound
     */
    private $outbound;

    /**
     * ShippingObserver constructor.
     *
     * @param \Amazon\MCF\Helper\Data            $helper
     * @param \Amazon\MCF\Model\Service\Outbound $outbound
     */
    public function __construct(
        \Amazon\MCF\Helper\Data $helper,
        \Amazon\MCF\Model\Service\Outbound $outbound
    ) {
        $this->helper = $helper;
        $this->outbound = $outbound;
    }

    /**
     * @param \Magento\Framework\Event\Observer $observer
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {

        if (!$this->helper->isEnabled()) {
            return;
        }

        $order = $observer->getData('order');
        $order->setAmazonOrderStatus($this->helper::ORDER_STATUS_CANCELLED);
        $order->save();

        // if order has transient property set, do not send to Amazon
        if ($order->getSkipAmazonCancel()) {
            return;
        }

        $response = $this->outbound->cancelFulfillmentOrder($order);
    }
}
