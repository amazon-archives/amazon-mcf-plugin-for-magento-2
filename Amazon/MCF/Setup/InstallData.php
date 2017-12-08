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

use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\InstallDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;

/**
 * @codeCoverageIgnore
 */
class InstallData implements InstallDataInterface {

    /**
     * @var \Magento\Eav\Setup\EavSetupFactory
     */
    private $_eavSetupFactory;

    /**
     * @var \Magento\Framework\App\Config\Storage\WriterInterface
     */
    private $_configWriter;

    public function __construct(EavSetupFactory $eavSetupFactory, WriterInterface $configWriter) {
        $this->_eavSetupFactory = $eavSetupFactory;
        $this->_configWriter = $configWriter;
    }

    /**
     * {@inheritdoc}
     */
    public function install(ModuleDataSetupInterface $setup, ModuleContextInterface $context) {

        $eavSetup = $this->_eavSetupFactory->create(['setup' => $setup]);

        $eavSetup->addAttribute(
            \Magento\Catalog\Model\Product::ENTITY,
            'amazon_mcf_asin_enabled',
            [
                'group' => 'Amazon Multi-Channel Fulfillment',
                'backend' => 'Magento\Catalog\Model\Product\Attribute\Backend\Boolean',
                'frontend' => '',
                'label' => 'Use Amazon Fulfillment',
                'input' => 'select',
                'class' => '',
                'source' => 'Magento\Catalog\Model\Product\Attribute\Source\Boolean',
                'global' => TRUE,
                'visible' => TRUE,
                'required' => FALSE,
                'user_defined' => FALSE,
                'default' => '0',
                'visible_on_front' => FALSE,
                'is_used_in_grid' => TRUE,
                'is_visible_in_grid' => TRUE,
                'is_filterable_in_grid' => TRUE,
                'apply_to'=>'simple'
            ]
        );

        $eavSetup->addAttribute(
            \Magento\Catalog\Model\Product::ENTITY,
            'amazon_mcf_merchant_sku',
            [
                'group' => 'Amazon Multi-Channel Fulfillment',
                'type' => 'varchar',
                'label' => 'Amazon Merchant SKU',
                'input' => 'text',
                'global' => TRUE,
                'visible' => TRUE,
                'required' => FALSE,
                'user_defined' => FALSE,
                'default' => '',
                'visible_on_front' => FALSE,
                'is_used_in_grid' => TRUE,
                'is_visible_in_grid' => FALSE,
                'is_filterable_in_grid' => FALSE,
                'note' => 'If SKU is entered here, it will be used instead of Magento generated SKU.',
                'apply_to'=>'simple'
            ]
        );
    }
}
