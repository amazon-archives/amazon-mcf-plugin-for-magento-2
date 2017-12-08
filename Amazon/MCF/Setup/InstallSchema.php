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

namespace Amazon\MCF\Setup;

use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

class InstallSchema implements InstallSchemaInterface {

    /**
     * {@inheritdoc}
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function install(SchemaSetupInterface $setup,
                            ModuleContextInterface $context)
    {

        $installer = $setup;

        $installer->startSetup();

        /*
         * add column `fulfilled_by_amazon` to `sales_order_grid`
         */
        $installer->getConnection()->addColumn($installer->getTable('sales_order_grid'), 'fulfilled_by_amazon', [
            'type' => \Magento\Framework\DB\Ddl\Table::TYPE_BOOLEAN,
            'comment' => 'Fulfilled By Amazon'
        ]);

        $installer->getConnection()->addColumn($installer->getTable('sales_order_grid'), 'amazon_order_status', [
            'type' => \Magento\Framework\DB\Ddl\Table::TYPE_BOOLEAN,
            'comment' => 'Amazon Order Status'
        ]);

        $installer->getConnection()->addColumn($installer->getTable('sales_order_grid'), 'amazon_submission_count', [
            'type' => \Magento\Framework\DB\Ddl\Table::TYPE_BOOLEAN,
            'comment' => 'Amazon Order Submission Attempt Count'
        ]);

        $installer->endSetup();

    }
}