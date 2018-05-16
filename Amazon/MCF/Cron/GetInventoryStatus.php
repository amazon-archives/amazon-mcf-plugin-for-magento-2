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

/**
 * Cron callback class that handles updating Magento inventory based on values
 * returned from calls to ListInventorySupply
 *
 * Class GetInventoryStatus
 *
 * @package Amazon\MCF\Cron
 */
class GetInventoryStatus
{

    /**
     * @var \Amazon\MCF\Helper\Data
     */
    private $helper;

    /**
     * @var \Amazon\MCF\Helper\Conversion
     */
    private $conversionHelper;

    /**
     * @var \Amazon\MCF\Model\Service\Inventory
     */
    private $inventory;

    /**
     * @var \Magento\Framework\Notification\NotifierPool
     */
    private $notifierPool;

    /**
     * GetInventoryStatus constructor.
     *
     * @param \Amazon\MCF\Helper\Data                      $helper
     * @param \Amazon\MCF\Helper\Conversion                $conversionHelper
     * @param \Amazon\MCF\Model\Service\Inventory          $inventory
     * @param \Magento\Framework\Notification\NotifierPool $notifierPool
     */
    public function __construct(
        \Amazon\MCF\Helper\Data $helper,
        \Amazon\MCF\Helper\Conversion $conversionHelper,
        \Amazon\MCF\Model\Service\Inventory $inventory,
        \Magento\Framework\Notification\NotifierPool $notifierPool
    ) {

        $this->helper = $helper;
        $this->conversionHelper = $conversionHelper;
        $this->inventory = $inventory;
        $this->notifierPool = $notifierPool;
    }

    /**
     * Cron process that retrieves changes based on current time - should run
     * hourly
     *
     * see crontab.xml for setup details
     */
    public function cronCurrentInventoryStatus()
    {
        // check if next token exists before proceeding with regular call.
        if ($this->helper->getInventoryNextToken()) {
            $response = $this->inventory->getListInventorySupplyByNextToken($this->helper->getInventoryNextToken());
        } else {
            // used -1 day since inventory changes are from given time to present,
            // current time would not return data.
            $startTime = gmdate("Y-m-d\TH:i:s.\\0\\0\\0\\Z", strtotime('-1 day'));
            $response = $this->inventory->getFulfillmentInventoryList([], $startTime);
        }

        if ($response) {
            // if there is a list of updates to provided skus, process them.
            $supplyList = $response->getListInventorySupplyResult()
                ->getInventorySupplyList()
                ->getmember();
            if ($supplyList) {
                $this->updateInventoryProcessStatus($supplyList);
            }

            $nextToken = $response->getListInventorySupplyResult()->getNextToken();

            if ($nextToken) {
                $this->helper->setInventoryNextToken($nextToken);
            } else {
                $this->helper->setInventoryNextToken('');
            }
        } else {
            // If no response, make sure next token is set to empty string for future calls.
            // It's possible call was made with invalid token
            $this->helper->setInventoryNextToken('');
        }
    }

    /**
     * Cron process that increments through a set number of SKUs each time
     * and gets/sets inventory supply values based on Amazon values.
     *
     * see crontab.xml for setup details
     */
    public function cronFullInventoryStatus()
    {

        $this->helper->logInventory('cronFullInventoryStatus() status called');
        if (!$this->helper->getInventoryProcessStatus()) {
            $skus = [];
            $skuData = $this->getAmazonFulfilledSkus();

            // if there are Amazon MCF enabled products, prepare to update inventory and
            // use alt sku for query if it exists.
            if ($skuData) {
                foreach ($skuData as $entityId => $data) {
                    if (isset($data['alt_sku'])) {
                        $skus[] = $data['alt_sku'];
                    } else {
                        $skus[] = $data['sku'];
                    }
                }

                if ($skus) {
                    $response = $this->inventory->getFulfillmentInventoryList(['member' => $skus]);

                    if ($response) {
                        // if there is a list of updates to provided skus, process them.
                        $supplyList = $response->getListInventorySupplyResult()
                            ->getInventorySupplyList()
                            ->getmember();
                        if ($supplyList) {
                            $this->processSupplyListData($supplyList, $skuData);
                        }
                    }
                }
            } else {
                // turn off process and reset start row
                $this->helper->setInventoryProcessStatus(true);
                $this->helper->setInventoryProcessRow(0);
            }
        }
    }

    /**
     * Compare data returned from Amazon MCF with current stock and sku data
     * and update stock values accordingly.
     *
     * @param $supplyList
     * @param $skuData
     */
    protected function processSupplyListData($supplyList, $skuData)
    {
        $skuQuantities = [];
        $updates = [];

        // process supply list from Amazon and check for availability/stock values.
        foreach ($supplyList as $item) {
            $sku = $item->getSellerSKU();
            $availability = !empty($item->getEarliestAvailability()) ? $item->getEarliestAvailability()
                ->getTimepointType() : '';
            $inStock = $item->getInStockSupplyQuantity();

            // check for items that are immediately available
            if ($sku && ($availability == 'Immediately') && $inStock) {
                $skuQuantities[$sku] = $inStock;
            }
        }

        // now compare Amazon MCF inventory data with original list of Magento inventory data
        foreach ($skuData as $entityId => $data) {
            // we need to relate the sku to the entity ID so that we can update stock value via StockRegistryInterface
            $updates[$entityId] = ['qty' => 0, 'sku' => $data['sku']];

            if (isset($skuQuantities[$data['sku']]) && $skuQuantities[$data['sku']]) {
                $updates[$entityId] = [
                    'qty' => $skuQuantities[$data['sku']],
                    'sku' => $data['sku'],
                ];
                // found a match in Magento so item is flagged accordingly.
                $skuData[$entityId]['hasData'] = true;
            } elseif (isset($data['alt_sku']) &&
                isset($skuQuantities[$data['alt_sku']]) &&
                $skuQuantities[$data['alt_sku']]) {
                // if returned data was keyed on the alternative manufacturer sku, need to
                // relate the update to the Magento SKU
                $updates[$entityId] = [
                    'qty' => $skuQuantities[$data['alt_sku']],
                    'sku' => $data['sku'],
                ];
                // found a match in Magento so item is flagged accordingly.
                $skuData[$entityId]['hasData'] = true;
            }
        }

        // Now that we have a list of updated inventory data, update stock information in Magento.
        if ($updates) {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $stockRegistry = $objectManager->create('Magento\CatalogInventory\Api\StockRegistryInterface');

            foreach ($updates as $entityId => $stockValue) {
                $stockItem = $stockRegistry->getStockItem($entityId);
                $stockItem->setData('qty', $stockValue['qty']);

                // make sure to set item in/out of stock if there is/isn't inventory. This will
                // hide/show it on the front end
                if ($stockValue['qty'] > 0) {
                    $stockItem->setData('is_in_stock', true);
                } else {
                    $stockItem->setData('is_in_stock', false);
                }
                $stockRegistry->updateStockItemBySku($stockValue['sku'], $stockItem);
            }

            // items that are not flagged as having inventory data will be added to a notification showing mismatches.
            $this->createInventoryNotifications($skuData);
        }
    }

    /**
     * Gets a list of all Amazon Fulfillment enabled sku data related to
     * product entity id
     *
     * @return array
     */
    protected function getAmazonFulfilledSkus()
    {
        $rowCount = $this->helper->getInventoryRowCount();
        $startRow = $this->helper->getInventoryProcessRow();
        $skus = [];

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $resource = $objectManager->get('Magento\Framework\App\ResourceConnection');
        $connection = $resource->getConnection();

        // Get 40 records at a time so that it falls within limit returned by Amazon MCF without paging.
        // Get all items that have amazon mcf related fields set
        $select = $connection->select()->from(
            ['pe' => 'catalog_product_entity'],
            ['entity_id', 'sku']
        )->joinLeft(
            ['ev' => 'catalog_product_entity_varchar'],
            'ev.entity_id = pe.entity_id',
            []
        )->joinLeft(
            ['ea' => 'eav_attribute'],
            'ea.attribute_id = ev.attribute_id',
            []
        )->where(
            "ea.attribute_code = 'amazon_mcf_asin_enabled' AND ev.value = 1"
        )->order(
            'pe.entity_id ASC'
        )->limit(
            $rowCount,
            $startRow
        );

        $result = $connection->fetchAll($select);

        foreach ($result as $row) {
            $skus[$row['entity_id']] = ['sku' => $row['sku'], 'hasData' => false];
        }

        if ($skus) {
            // check for Amazon Merchant SKUs - it will be used in query instead of Magento SKU
            $select = $connection->select()->from(
                ['pe' => 'catalog_product_entity'],
                ['entity_id', 'sku']
            )->joinLeft(
                ['ev' => 'catalog_product_entity_varchar'],
                'ev.entity_id = pe.entity_id',
                ['value']
            )->joinLeft(
                ['ea' => 'eav_attribute'],
                'ea.attribute_id = ev.attribute_id',
                []
            )->where(
                "ea.attribute_code = 'amazon_mcf_merchant_sku' AND ev.value != 1 AND ev.value IS NOT NULL"
            )->order(
                'pe.entity_id ASC'
            )->limit(
                $rowCount,
                $startRow
            );

            $result = $connection->fetchAll($select);

            foreach ($result as $row) {
                if ($row['value']) {
                    $skus[$row['entity_id']]['alt_sku'] = $row['value'];
                }
            }

            $this->helper->setInventoryProcessRow($startRow + count($skus));
        }

        return $skus;
    }

    /**
     * Perform updates on Magento inventory supply based on data returned from
     * Amazon MCF API
     *
     * @param $supplyList
     */
    protected function updateInventoryProcessStatus($supplyList)
    {
        $skuQuantities = $this->getSkuQuantities($supplyList);

        if ($skuQuantities) {
            $matches = $this->getMagentoInventoryData($skuQuantities);

            if ($matches) {
                $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
                $stockRegistry = $objectManager->create('Magento\CatalogInventory\Api\StockRegistryInterface');

                foreach ($matches as $productId => $stockValue) {
                    $stockItem = $stockRegistry->getStockItem($productId);
                    $stockItem->setData('qty', $stockValue['qty']);
                    $stockRegistry->updateStockItemBySku($stockValue['sku'], $stockItem);
                }
            }

            $this->createMissingInventoryNotifications($skuQuantities, $matches);
        }
    }

    /**
     * Finds matches with SKUs returned from Amazon MCF API with products in Magento system
     *
     * @param $skuQuantities
     *
     * @return array
     */
    protected function getMagentoInventoryData($skuQuantities)
    {
        $matches = [];

        $skus = [];

        foreach ($skuQuantities as $sku => $data) {
            $skus[] = $sku;
        }

        if ($skus) {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $resource = $objectManager->get('Magento\Framework\App\ResourceConnection');
            $connection = $resource->getConnection();

            // Get 45 records at a time so that it falls within limit returned by Amazon MCF without paging.
            // Get all items that have amazon mcf related fields set
            $select = $connection->select()->from(
                ['pe' => 'catalog_product_entity'],
                ['entity_id', 'sku']
            )->joinLeft(
                ['ev' => 'catalog_product_entity_varchar'],
                'ev.entity_id = pe.entity_id',
                []
            )->joinLeft(
                ['ea' => 'eav_attribute'],
                'ea.attribute_id = ev.attribute_id',
                []
            )->where(
                'pe.sku IN (?)',
                $skus
            )->where(
                "ea.attribute_code = 'amazon_mcf_asin_enabled' AND ev.value = 1"
            );

            $result = $connection->fetchAll($select);

            foreach ($result as $row) {
                $matches[$row['entity_id']] = [
                    'sku' => $row['sku'],
                    'qty' => isset($skuQuantities[$row['sku']]) ? $skuQuantities[$row['sku']] : 0,
                ];
            }

            $select = $connection->select()->from(
                ['pe' => 'catalog_product_entity'],
                ['entity_id', 'sku']
            )->joinLeft(
                ['ev' => 'catalog_product_entity_varchar'],
                'ev.entity_id = pe.entity_id',
                ['value']
            )->joinLeft(
                ['ea' => 'eav_attribute'],
                'ea.attribute_id = ev.attribute_id',
                []
            )->where(
                "ea.attribute_code = 'amazon_mcf_merchant_sku'"
            )->where(
                'ev.value IN (?)',
                $skus
            );

            $result = $connection->fetchAll($select);

            foreach ($result as $row) {
                $matches[$row['entity_id']] = [
                    'sku' => $row['sku'],
                    'qty' => isset($skuQuantities[$row['value']]) ? $skuQuantities[$row['value']] : 0,
                ];
            }
        }

        return $matches;
    }

    /**
     * Extracts simplified array of skus associated with quantities
     *
     * @param $supplyList
     *
     * @return array
     */
    protected function getSkuQuantities($supplyList)
    {
        $skuQuantities = [];

        foreach ($supplyList as $item) {
            $sku = $item->getSellerSKU();
            $availability = !empty($item->getEarliestAvailability()) ? $item->getEarliestAvailability()
                ->getTimepointType() : '';

            $inStock = $item->getInStockSupplyQuantity();
            $skuQuantities[$sku] = $inStock;
        }

        return $skuQuantities;
    }

    /**
     * Adds notification for items that do not have corresponding data on
     * Amazon MCF
     *
     * @param $skuData
     */
    private function createInventoryNotifications($skuData)
    {
        $skus = [];

        foreach ($skuData as $entity_id => $data) {
            if (!$data['hasData']) {
                if (isset($data['alt_sku'])) {
                    $skus[] = $data['alt_sku'];
                } else {
                    $skus[] = $data['sku'];
                }
            }
        }

        if ($skus) {
            $this->notifierPool->addNotice(
                'Amazon Fulfillment Inventory Status',
                'The following SKUs have no associated Amazon Fulfillment data or no available inventory: '
                . implode(', ', $skus) . '. The stock quantities for these SKUs has been set to 0.
                If this is incorrect, please disable Amazon Fulfillment for these products or ensure
                Amazon Merchant SKU is correct.'
            );
        } else {
            $this->notifierPool->addNotice(
                'Amazon Fulfillment Inventory Status',
                'All products marked \'Fulfilled by Amazon\' have successfully had inventory updated.
                No missing or mismatched products found.'
            );
        }
    }

    /**
     * Creates appropriate notification explaining that Amazon returned values for SKUs/Products that do not
     * exist in Magento
     *
     * @param $skuQuantities
     * @param $matches
     */
    private function createMissingInventoryNotifications($skuQuantities, $matches)
    {
        $updated = [];
        $skus = [];

        foreach ($matches as $sku => $data) {
            $updated[] = $data['sku'];
        }

        foreach ($skuQuantities as $sku => $value) {
            if (!in_array($sku, $updated)) {
                $skus[] = $sku;
            }
        }

        if ($skus) {
            $this->notifierPool->addNotice(
                'Amazon Fulfillment Inventory Status',
                'The following SKUs have no associated Magento Product: ' . implode(', ', $skus)
                . '. Please create these products and assign the Amazon Sku to the Merchant Sku field and enable 
                the product for Amazon Fulfillment.'
            );
        }
    }
}
