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

namespace Amazon\MCF\Controller\Ajax;

/**
 * Class FBAData
 * Controller handles JSON requests for product page estimate block.
 *
 * @package Amazon\MCF\Controller\Ajax
 */
class FBAData extends \Magento\Framework\App\Action\Action
{

    /**
     * @var \Magento\Framework\Controller\Result\JsonFactory
     */
    private $resultJsonFactory;

    /**
     * @var \Amazon\MCF\Model\Service\Outbound
     */
    private $outbound;

    /**
     * @var \Magento\Framework\Json\Helper\Data
     */
    private $jsonHelper;

    /**
     * @var \Amazon\MCF\Helper\Data
     */
    private $helperData;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $storeManager;

    /**
     * FBAData constructor.
     *
     * @param \Magento\Backend\App\Action\Context              $context
     * @param \Amazon\MCF\Model\Service\Outbound               $outbound
     * @param \Magento\Framework\Json\Helper\Data              $jsonHelper
     * @param \Amazon\MCF\Helper\Data                          $data
     * @param \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory
     */
    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Amazon\MCF\Model\Service\Outbound $outbound,
        \Magento\Framework\Json\Helper\Data $jsonHelper,
        \Amazon\MCF\Helper\Data $data,
        \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory
    ) {

        $this->outbound = $outbound;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->jsonHelper = $jsonHelper;
        $this->helperData = $data;

        $om = $context->getObjectManager();
        $this->storeManager = $om->get('Magento\Store\Model\StoreManagerInterface');
        parent::__construct($context);
    }


    /**
     * @param $product_id
     *
     * @return \Magento\Catalog\Model\Product
     */
    protected function _getProduct($product_id) 
    {
        // Note - used object manager rather than product repository because the class would not
        // load during constructor/use dependency injection.
        return $this->_objectManager->get('Magento\Catalog\Model\Product')
            ->load($product_id);
    }

    /**
     * Check Amazon Fulfillment for shipping information
     *
     * Expects a POST. ex for JSON {"pcode":"98101", "pid":"4", "qty": "1"}
     *
     * @return                                       \Magento\Framework\Controller\ResultInterface
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function execute() 
    {
        $params = null;

        $response = [
            'result' => true,
            'message' => __('No data available.'),
            'data' => [],
        ];

        try {
            $params = $this->_request->getParams();

        } catch (\Exception $e) {
            $response = [
                'result' => false,
                'message' => __('Unable to make Amazon FBA Request.'),
            ];
        }

        if ($params && isset($params['pcode']) && $params['pcode'] && isset($params['pid'])
            && is_numeric($params['pid']) && isset($params['qty']) && is_numeric($params['qty'])
        ) {

            $storeId = $this->storeManager->getStore()->getId();

            $pcode = $params['pcode'];
            $pid = $params['pid'];
            $params['qty'] ? $qty = $params['qty'] : $qty = 1;

            $address = [
                'CountryCode' => $this->helperData->getStoreCountry($storeId),
                'PostalCode' => $pcode,
                'City' => 'Placeholder',
                'Line1' => '100 main st',
                'Name' => 'john doe',
                'StateOrProvinceCode' => 'WA',
            ];

            $product = $this->_getProduct($pid);
            $items = [];

            if ($product) {
                $sku = $product->getSku();

                // check for alternative sku
                if ($product->getAmazonMcfMerchantSku()) {
                    $sku = $product->getAmazonMcfMerchantSku();
                }

                if ($sku) {
                    $items['member'][] = [
                        'SellerSKU' => $sku,
                        'SellerFulfillmentOrderItemId' => $pid,
                        'Quantity' => $qty,
                    ];
                }
            }

            if ($items) {
                $rates = $this->outbound->getProductEstimate($address, $items);

                if ($rates) {
                    $response = [
                        'result' => true,
                        'message' => __('Rates available.'),
                        'data' => $rates,
                    ];
                }
            }
        }

        /**
         * @var \Magento\Framework\Controller\Result\Json $resultJson
         */
        $resultJson = $this->resultJsonFactory->create();
        return $resultJson->setData($response);
    }
}
