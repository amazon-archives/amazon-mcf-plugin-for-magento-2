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

namespace Amazon\MCF\Setup;

use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\UpgradeDataInterface;
use Magento\Sales\Setup\SalesSetupFactory;

/**
 * Class UpgradeData
 *
 * Adds custom 'fulfilled_by_amazon' attribute to orders
 *
 * @package Amazon\MCF\Setup
 */
class UpgradeData implements UpgradeDataInterface
{

    /**
     * @var SalesSetupFactory
     */
    private $salesSetupFactory;

    /**
     * UpgradeData constructor.
     *
     * @param \Magento\Sales\Setup\SalesSetupFactory $salesSetupFactory
     */
    public function __construct(
        SalesSetupFactory $salesSetupFactory
    ) {
        $this->salesSetupFactory = $salesSetupFactory;
    }

    /**
     * @inheritdoc
     */
    public function upgrade(
        ModuleDataSetupInterface $setup,
        ModuleContextInterface $context
    ) {

        /**
* 
         *
 * @var \Magento\Sales\Setup\SalesSetup $salesInstaller 
*/
        $salesInstaller = $this->salesSetupFactory->create(['resourceName' => 'sales_setup', 'setup' => $setup]);

        $setup->startSetup();

        //Add attributes to quote
        $entityAttributesCodes = [
            'fulfilled_by_amazon' => \Magento\Framework\DB\Ddl\Table::TYPE_BOOLEAN,
            'amazon_order_status' => \Magento\Framework\DB\Ddl\Table::TYPE_TEXT,
            'amazon_submission_count' => \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER
        ];

        foreach ($entityAttributesCodes as $code => $type) {
            $salesInstaller->addAttribute(
                'order',
                $code,
                ['type' => $type, 'length'=> 255, 'visible' => true,'nullable' => true]
            );
            $salesInstaller->addAttribute(
                'invoice',
                $code,
                ['type' => $type, 'length'=> 255, 'visible' => true,'nullable' => true]
            );
        }

        $setup->endSetup();
    }
}
