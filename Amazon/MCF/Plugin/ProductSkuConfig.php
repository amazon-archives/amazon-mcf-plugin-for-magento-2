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


class ProductSkuConfig {

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
     * @var \Magento\CatalogInventory\Api\StockRegistryInterface
     */
    protected $_stockRegistry;

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
        \Magento\CatalogInventory\Api\StockRegistryInterface $stockRegistry,
        \Magento\Framework\Message\ManagerInterface $messageManager) {

        $this->_stockRegistry = $stockRegistry;
        $this->_configHelper = $data;
        $this->_messageManager = $messageManager;
        $this->_inventory = $inventory;
    }

    public function afterSave(\Magento\Catalog\Model\Product $product) {
        $skus = [];

        if ($product && $product->getAmazonMcfAsinEnabled()) {

            $skus[] = $product->getSku();

            if ($product->getAmazonMcfMerchantSku()) {
                $skus[] = $product->getAmazonMcfMerchantSku();
            }

            if ($skus) {
                $exists = FALSE;
                $response = $this->_inventory->getFulfillmentInventoryList(['member' => $skus]);

                if ($response) {
                    $supplyList = $response->getListInventorySupplyResult()
                        ->getInventorySupplyList()
                        ->getmember();

                    if ($supplyList) {

                        $quantities = 0;

                        foreach ($supplyList as $item) {

                            if ($item->getASIN()) {
                                $exists = TRUE;
                            }

                            $quantities += $item->getInStockSupplyQuantity();
                        }
                    }
                }

                if ($exists) {
                    $stockItem = $this->_stockRegistry->getStockItem($product->getId());
                    $stockItem->setData('qty', $quantities);

                    // make sure to set item in/out of stock if there is/isn't inventory. This will hide/show it on the front end
                    if ($quantities > 0) {
                        $stockItem->setData('is_in_stock', TRUE);
                    }
                    else {
                        $stockItem->setData('is_in_stock', FALSE);
                    }

                    $this->_stockRegistry->updateStockItemBySku($product->getSku(), $stockItem);

                    $this->_messageManager->addSuccessMessage('The SKU or alternate Merchant SKU has an associated Seller Sku at Amazon. ' . $quantities . ' item(s) are in stock. The amount of inventory has been updated.');
                }
                else {
                    $this->_messageManager->addErrorMessage('The SKU entered "' . $product->getSku() . '" does not have an associated Seller Sku at Amazon. Please check the SKU value matches between systems.');
                }
            }
        }
    }
}