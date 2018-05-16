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

namespace Amazon\MCF\Controller\Adminhtml\System\Config;

/**
 * Provides behavior for AJAX callback used by AmazonSynchronize button block.
 *
 * Class AmazonSynchronize
 *
 * @package Amazon\MCF\Controller\Adminhtml\System\Config
 */
class AmazonSynchronize extends \Magento\Backend\App\Action
{

    /**
     * @var \Amazon\MCF\Helper\Data
     */
    private $configHelper;

    /**
     * @var \Magento\Framework\Controller\Result\JsonFactory
     */
    private $resultJsonFactory;
    
    /**
     * AmazonSynchronize constructor.
     *
     * @param \Magento\Backend\App\Action\Context              $context
     * @param \Amazon\MCF\Helper\Data                          $helper
     * @param \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory
     */
    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Amazon\MCF\Helper\Data $helper,
        \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory
    ) {

        $this->configHelper = $helper;
        $this->resultJsonFactory = $resultJsonFactory;
        parent::__construct($context);
    }

    /**
     * Synchronize callback - when config synchronize button is pressed,
     * sets sync flag so that all inventory items will be processed during cron.
     *
     * @return void
     */
    public function execute()
    {
        $responseText = __('Inventory Synchronization has been flagged to begin running each inventory 
        cron job until completed.');

        $this->messageManager->addSuccessMessage($responseText);
        $this->configHelper->setInventoryProcessStatus(false);
        $this->configHelper->setInventoryProcessRow(0);
        $result = $this->resultJsonFactory->create();
        return $result->setData(['success' => true, 'responseMessage' => $responseText]);
    }
}
