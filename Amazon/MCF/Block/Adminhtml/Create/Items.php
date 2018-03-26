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
namespace Amazon\MCF\Block\Adminhtml\Create;

/**
 * Adminhtml shipment items grid
 */
class Items extends \Magento\Sales\Block\Adminhtml\Items\AbstractItems
{
    /**
     * Sales data
     *
     * @var \Magento\Sales\Helper\Data
     */
    private $salesData;

    /**
     * @var \Magento\Shipping\Model\CarrierFactory
     */
    private $carrierFactory;

    /**
     * @var \Amazon\MCF\Helper\Data
     */
    private $configHelper;

    /**
     * @var \Magento\Catalog\Model\ProductFactory 
     */
    private $productLoader;

    /**
     * @param \Magento\Backend\Block\Template\Context                   $context
     * @param \Magento\CatalogInventory\Api\StockRegistryInterface      $stockRegistry
     * @param \Magento\CatalogInventory\Api\StockConfigurationInterface $stockConfiguration
     * @param \Magento\Framework\Registry                               $registry
     * @param \Magento\Sales\Helper\Data                                $salesData
     * @param \Magento\Shipping\Model\CarrierFactory                    $carrierFactory
     * @param \Amazon\MCF\Helper\Data                                   $configHelper
     * @param \Magento\Catalog\Model\ProductFactory                     $productloader
     * @param array                                                     $data
     */
    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        \Magento\CatalogInventory\Api\StockRegistryInterface $stockRegistry,
        \Magento\CatalogInventory\Api\StockConfigurationInterface $stockConfiguration,
        \Magento\Framework\Registry $registry,
        \Magento\Sales\Helper\Data $salesData,
        \Magento\Shipping\Model\CarrierFactory $carrierFactory,
        \Amazon\MCF\Helper\Data $configHelper,
        \Magento\Catalog\Model\ProductFactory $productloader,
        array $data = []
    ) {
        $this->configHelper = $configHelper;
        $this->salesData = $salesData;
        $this->carrierFactory = $carrierFactory;
        $this->productLoader = $productloader;
        parent::__construct($context, $stockRegistry, $stockConfiguration, $registry, $data);
    }

    /**
     * Retrieve invoice order
     *
     * @return \Magento\Sales\Model\Order
     */
    public function getOrder()
    {
        return $this->getShipment()->getOrder();
    }

    /**
     * Check to see if items can be fulfilled by amazon
     *
     * @return bool
     */
    public function FBAEnabled() 
    {
        return ($this->configHelper->isEnabled() && $this->configHelper->amazonCarrierEnabled());
    }

    /**
     * Flags a shipment item row with class indicating it is fulfilled by Amazon
     *
     * @param \Magento\Sales\Model\Order\Shipment\Item $item
     *
     * @return string
     */
    public function isFBAItem(\Magento\Sales\Model\Order\Shipment\Item $item) 
    {

        if ($this->FBAEnabled()) {
            $product = $this->productLoader->create()
                ->load($item->getProductId());

            if ($product->getAmazonMcfAsinEnabled()) {
                return ' isFBA';
            }
        }

        return '';
    }

    /**
     * Prints warning message for use with JavaScript flag.
     *
     * @return \Magento\Framework\Phrase|string
     */
    public function getFBAWarningMessage() 
    {
        if ($this->FBAEnabled()) {
            return __('This item will be updated as shipped after FBA shipping completed, are you sure you want to 
            manually ship this item in Magento?');
        }

        return '';
    }

    /**
     * Retrieve source
     *
     * @return \Magento\Sales\Model\Order\Shipment
     */
    public function getSource()
    {
        return $this->getShipment();
    }

    /**
     * Retrieve shipment model instance
     *
     * @return \Magento\Sales\Model\Order\Shipment
     */
    public function getShipment()
    {
        return $this->_coreRegistry->registry('current_shipment');
    }

    /**
     * Prepare child blocks
     *
     * @return string
     */
    protected function _beforeToHtml()
    {
        $this->addChild(
            'submit_button',
            'Magento\Backend\Block\Widget\Button',
            [
                'label' => __('Submit Shipment'),
                'class' => 'save submit-button primary',
                'onclick' => 'submitShipment(this);'
            ]
        );

        return parent::_beforeToHtml();
    }

    /**
     * Format given price
     *
     * @param  float $price
     * @return string
     */
    public function formatPrice($price)
    {
        return $this->getShipment()->getOrder()->formatPrice($price);
    }

    /**
     * Retrieve HTML of update button
     *
     * @return string
     */
    public function getUpdateButtonHtml()
    {
        return $this->getChildHtml('update_button');
    }

    /**
     * Get url for update
     *
     * @return string
     */
    public function getUpdateUrl()
    {
        return $this->getUrl('sales/*/updateQty', ['order_id' => $this->getShipment()->getOrderId()]);
    }

    /**
     * Check possibility to send shipment email
     *
     * @return bool
     */
    public function canSendShipmentEmail()
    {
        return $this->salesData->canSendNewShipmentEmail($this->getOrder()->getStore()->getId());
    }

    /**
     * Checks the possibility of creating shipping label by current carrier
     *
     * @return bool
     */
    public function canCreateShippingLabel()
    {
        $shippingCarrier = $this->carrierFactory->create(
            $this->getOrder()->getShippingMethod(true)->getCarrierCode()
        );
        return $shippingCarrier && $shippingCarrier->isShippingLabelsAvailable();
    }

    public function setTemplate($template)
    {
        return parent::setTemplate('Amazon_MCF::create/items.phtml');
    }
}
