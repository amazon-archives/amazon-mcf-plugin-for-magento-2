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

namespace Amazon\MCF\Model\Config;

/**
 * Class Endpoints
 *
 * @package Amazon\MCF\Model\Config
 */
class Endpoints implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * Provides a list of Amazon MCF endpoint paths based on available regions
     * {@inheritdoc}
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'https://mws.amazonservices.com', 'label' => 'United States'],
            ['value' => 'https://mws.amazonservices.ca', 'label' => 'Canada'],
            ['value' => 'https://mws.amazonservices.com.mx', 'label' => 'Mexico'],
            ['value' => 'https://mws-eu.amazonservices.com', 'label' => 'Germany'],
            ['value' => 'https://mws-eu.amazonservices.com', 'label' => 'Spain'],
            ['value' => 'https://mws-eu.amazonservices.com', 'label' => 'France'],
            ['value' => 'https://mws-eu.amazonservices.com', 'label' => 'Italy'],
            ['value' => 'https://mws-eu.amazonservices.com', 'label' => 'United Kingdom'],
            ['value' => 'https://mws.amazonservices.in', 'label' => 'India'],
            ['value' => 'https://mws.amazonservices.jp', 'label' => 'Japan'],
            ['value' => 'https://mws.amazonservices.com.cn', 'label' => 'China'],
            ['value' => 'custom', 'label' => 'Custom'],
        ];
    }
}
