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

namespace Amazon\MCF\Model\Service;

use Amazon\MCF\Helper\Data;

/**
 * Forms base class for instantiating the appropriate version of an Amazon MCF Service
 *
 * Class MCFAbstract
 *
 * @package Amazon\MCF\Model\Service
 */
class MCFAbstract {

    protected $_helper;

    protected $_modulePath;

    const SERVICE_NAME = null;
    const SERVICE_CLASS = null;

    /**
     * MCFAbstract constructor.
     *
     * @param \Amazon\MCF\Helper\Data $helper
     */
    public function __construct(Data $helper) {
        $this->_helper = $helper;

        $om = \Magento\Framework\App\ObjectManager::getInstance();
        $reader = $om->get('Magento\Framework\Module\Dir\Reader');
        $this->_modulePath = $reader->getModuleDir('', 'Amazon_MCF');
    }

    /**
     * Each implementing class should override this with correct service version
     *
     * @return string
     */
    protected function getServiceVersion()
    {
        return '2017-01-01';
    }

    protected function getModulePath() {
        return $this->_modulePath;
    }

    /**
     * @param null $store
     *
     * @return string
     */
    protected function getServiceUrl($store = null)
    {
        return $this->_helper->getEndpoint($store) . $this::SERVICE_NAME . $this->getServiceVersion();
    }


    /**
     * Returns appropriate client class based on debug settings
     *
     * @return string
     */
    protected function getServiceClass()
    {
        return $this::SERVICE_CLASS . ($this->_helper->isDebug() ? '_Mock' : '_Client');
    }

    /**
     * Instantiates Amazon MCF Service class
     *
     * @return mixed
     */
    protected function getClient() {
        $config = [
            'ServiceURL' => $this->getServiceUrl(),
        ];

        $serviceClass = $this->getServiceClass();

        $client = new $serviceClass(
            $this->_helper->getAccessKeyId(),
            $this->_helper->getSecretAccessKey(),
            $config,
            $this->_helper->getApplicationName(),
            $this->_helper->getApplicationVersion()
        );

        return $client;
    }

    /**
     * @return array
     */
    protected function getRequest($params = array(), $store = null)
    {
        return array_merge(
            array(
                'SellerId' => $this->_helper->getSellerId($store)
            ),
            $params
        );
    }
}