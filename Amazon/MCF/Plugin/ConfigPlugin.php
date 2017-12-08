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

namespace Amazon\MCF\Plugin;


class ConfigPlugin {

    /**
     * @var \Amazon\MCF\Model\Service\Inventory
     */
    protected $_inventory;

    /**
     * @var \Amazon\MCF\Helper\Data
     */
    protected $_configHelper;

    /**
     * @var \Magento\Framework\Message\ManagerInterface
     */
    protected $_messageManager;

    /**
     * ConfigPlugin constructor.
     *
     * @param \Amazon\MCF\Model\Service\Inventory $inventory
     * @param \Amazon\MCF\Helper\Data $data
     * @param \Magento\Framework\Message\ManagerInterface $messageManager
     */
    public function __construct(
        \Amazon\MCF\Model\Service\Inventory $inventory,
        \Amazon\MCF\Helper\Data $data,
        \Magento\Framework\Message\ManagerInterface $messageManager) {

        $this->_configHelper = $data;
        $this->_messageManager = $messageManager;
        $this->_inventory = $inventory;
    }

    /**
     * After config is saved, make a check for section and run credential check.
     *
     * @param \Magento\Config\Model\Config $subject
     */
    public function afterSave(
        \Magento\Config\Model\Config $subject
    ) {

        // Only want to make the check in amazon fba section.
        if ($subject->getSection() == 'amazon_fba_connect') {
            $result = $this->_inventory->checkCredentials();
            $message = $result['message'];

            if ($result['result'] == 'success' && !$this->_configHelper->amazonCarrierEnabled($subject->getStore())) {
                $message .= ' '.__('To enable Amazon Multi-Channel Fulfillment shipping speeds and rates for your customers at cart & checkout, please enable the FBA Shipping method in Sales > Shipping Methods');
            }
            if ($result['result'] == 'success') {
                $this->_messageManager->addSuccessMessage($message);
            }
            else {
                $this->_messageManager->addErrorMessage($message);
            }
        }

    }
}