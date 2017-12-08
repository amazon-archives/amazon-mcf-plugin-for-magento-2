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
namespace Amazon\MCF\Block\Catalog\Product\View;

use Magento\Catalog\Model\Product;

/**
 * Class ProductShippingBlock
 *
 * @package Amazon\MCF\Block\Catalog\Product\View
 */
class ProductShippingBlock extends \Magento\Framework\View\Element\Template {

    /**
     * @var Product
     */
    protected $_product = null;

    /**
     * Core registry
     *
     * @var \Magento\Framework\Registry
     */
    protected $_coreRegistry = null;

    /**
     * @var \Amazon\MCF\Helper\Data
     */
    protected $_configHelper;

    /**
     * ProductShippingBlock constructor.
     *
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Amazon\MCF\Helper\Data $configHelper
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Framework\Registry $registry,
        \Amazon\MCF\Helper\Data $configHelper,
        array $data = []
    ) {
        $this->_coreRegistry = $registry;
        $this->_configHelper = $configHelper;
        parent::__construct($context, $data);
    }

    /**
     * Set template to itself
     *
     * @return $this
     */
    protected function _prepareLayout()
    {
        parent::_prepareLayout();
        if (!$this->getTemplate()) {
            $this->setTemplate('catalog/product/view/productshipping.phtml');
        }
        return $this;
    }


    /**
     * Get's an instance of product on which this block is currently displayed
     *
     * @return Product
     */
    public function getProduct()
    {
        if (!$this->_product) {
            $this->_product = $this->_coreRegistry->registry('product');
        }

        return $this->_product;
    }

    /**
     * Gets path to AJAX callback
     *
     * @return string
     */
    public function getAjaxRateUrl() {
        return $this->getUrl('amazonfba/Ajax/FBAData');
    }

    /**
     * Get's ID of product that the block is displayed on
     * @return int
     */
    public function getProductId() {
        return $this->getProduct()->getId();
    }

    /**
     * Checks to see if product is enabled for use with Amazon Fulfillment
     * @return bool
     */
    public function isFBAEnabled() {
        $product = $this->getProduct();
        $storeId = $this->_storeManager->getStore()->getId();

        if ($product->getAmazonMcfAsinEnabled() && $this->_configHelper->displayDeliveryBlock($storeId)) {
            return TRUE;
        }

        return FALSE;
    }
}