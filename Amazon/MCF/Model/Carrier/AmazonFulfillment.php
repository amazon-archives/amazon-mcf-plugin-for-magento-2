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

namespace Amazon\MCF\Model\Carrier;


use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;

/**
 * Custom Carrier - Handles shipping via Amazon MCF API
 * Class AmazonFulfillment
 *
 * @package Amazon\MCF\Model\Carrier
 */
class AmazonFulfillment extends AbstractCarrier implements CarrierInterface {

    /**
     * Code of the carrier
     *
     * @var string
     */
    const CODE = 'amazonfulfillment';

    /**
     * Code of the carrier
     *
     * @var string
     */
    protected $_code = self::CODE;

    /**
     * @var bool
     */
    protected $_isFixed = FALSE;

    /**
     * @var array
     */
    protected $_methodTitles = [
        'standard' => 'Standard',
        'expedited' => 'Expedited',
        'priority' => 'Priority',
    ];

    /**
     * @var \Amazon\MCF\Model\Service\Outbound
     */
    protected $_outbound;

    /**
     * @var
     */
    protected $_request;

    /**
     * @var
     */
    protected $_address;

    /**
     * @var \Amazon\MCF\Helper\Data
     */
    protected $_configHelper;

    /**
     * @var \Amazon\MCF\Helper\Conversion
     */
    protected $_conversionHelper;

    /**
     * @var \Magento\Shipping\Model\Rate\ResultFactory
     */
    protected $_rateResultFactory;

    /**
     * @var \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory
     */
    protected $_rateMethodFactory;

    /**
     * @var \Magento\Framework\Logger\Monolog
     */
    protected $_logger;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $_storeManager;

    /**
     * AmazonFulfillment constructor.
     *
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory
     * @param \Magento\Framework\Logger\Monolog $logger
     * @param \Magento\Shipping\Model\Rate\ResultFactory $rateResultFactory
     * @param \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory
     * @param \Amazon\MCF\Helper\Data $configHelper
     * @param \Amazon\MCF\Helper\Conversion $conversionHelper
     * @param \Amazon\MCF\Model\Service\Outbound $outbound
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory,
        \Magento\Framework\Logger\Monolog $logger,
        \Magento\Shipping\Model\Rate\ResultFactory $rateResultFactory,
        \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory,
        \Amazon\MCF\Helper\Data $configHelper,
        \Amazon\MCF\Helper\Conversion $conversionHelper,
        \Amazon\MCF\Model\Service\Outbound $outbound,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        array $data = []
    ) {
        $this->_logger = $logger;
        $this->_conversionHelper = $conversionHelper;
        $this->_configHelper = $configHelper;
        $this->_outbound = $outbound;
        $this->_rateResultFactory = $rateResultFactory;
        $this->_rateMethodFactory = $rateMethodFactory;
        $this->_storeManager = $storeManager;
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    /**
     * Generates Amazon Fulfillment rate list
     *
     * @param RateRequest $request
     *
     * @return \Magento\Shipping\Model\Rate\Result|bool
     */
    public function collectRates(\Magento\Quote\Model\Quote\Address\RateRequest $request) {
        if (!$this->getConfigFlag('active')) {
            return FALSE;
        }

        if ($this->_configHelper->isEnabled()) {
            /** @var \Magento\Shipping\Model\Rate\Result $result */
            $result = $this->_rateResultFactory->create();
            $this->prepareShippingRequest($request);
            $rates = $this->getShippingRates($request);
            $storeId = $this->_storeManager->getStore()->getId();
            $showDates = $this->_configHelper->displayEstimatedArrival($storeId);

            if ($rates) {

                foreach ($rates as $title => $values) {

                    if ($showDates && isset($values['date'])) {
                        $methodTitle = 'By ' . date('l, M. j', strtotime($values['date'])) . ' - ' . $title;
                    }
                    else {
                        $methodTitle = $title;
                    }

                    $method = $this->_rateMethodFactory->create();

                    $method->setCarrier('amazonfulfillment');
                    $method->setCarrierTitle('Fulfillment By Amazon');

                    $method->setMethod(strtolower($title));
                    $method->setMethodTitle($methodTitle);

                    $method->setPrice($values['price']);
                    $method->setCost($values['price']);

                    $result->append($method);
                }
            }

        }
        else {
            $this->_logger->addDebug('Cannot be fulfilled by Amazon - extension is not enabled.');
            return FALSE;
        }

        return $result;
    }

    /**
     * Gets checkout cart list of items and parses them into a format the
     * Amazon API can use
     *
     * @param \Magento\Quote\Model\Quote\Address\RateRequest $request
     *
     * @return array
     */
    protected function getItemsFromShippingRateRequest(\Magento\Quote\Model\Quote\Address\RateRequest $request) {
        $items = $request->getAllItems();

        return $this->_conversionHelper->getAmazonItemsArrayFromRateRequest($items);
    }

    /**
     * Generates shipping rates array
     *
     * @param \Magento\Quote\Model\Quote\Address\RateRequest $request
     *
     * @return array|null
     */
    protected function getShippingRates(\Magento\Quote\Model\Quote\Address\RateRequest $request) {
        $rates = [];
        $items = $this->getItemsFromShippingRateRequest($request);

        if (empty($items)) {
            return $rates;
        }

        if ($this->_address) {

            $fulfillmentPreview = $this->_outbound->getFulfillmentPreview($this->_address, $items);

            if (!empty($fulfillmentPreview)) {
                $rates = $this->getRatesFromFulfillmentPreview($fulfillmentPreview);
            }

            if (empty($rates)) {
                $rates = ['Standard' => ['price' => $this->_configHelper->getDefaultStandardShippingCost($request->getStoreId())]];
            }
            elseif (!$this->_configHelper->displayShippingCosts($request->getStoreId())) {
                $defaultRates = [
                    'Standard' => ['price' => $this->_configHelper->getDefaultStandardShippingCost($request->getStoreId())],
                    'Expedited' => ['price' => $this->_configHelper->getDefaultExpeditedShippingCost($request->getStoreId())],
                    'Priority' => ['price' => $this->_configHelper->getDefaultPriorityShippingCost($request->getStoreId())],
                ];

                // Only update rates that are returned for the destination
                foreach ($rates as $speed => $rate) {
                    $rates[$speed]['price'] = $defaultRates[$speed]['price'];
                }
            }
        }

        // check if any item is amazon fulfilled - need to present default pricing if no value returned
        if (!$rates) {
            $isFBA = FALSE;

            $items = $request->getAllItems();
            if ($items) {
                foreach($items as $item) {
                    $isFBA = $item->getProduct()->getAmazonMcfAsinEnabled();
                    if ($isFBA) {
                        break;
                    }
                }

                if ($isFBA) {
                    $rates = ['Standard' => ['price' => $this->_configHelper->getDefaultStandardShippingCost($request->getStoreId())]];
                }
            }
        }

        return $rates;
    }

    /**
     * Sets up rates list from Amazon fulfillment/shipping preview information
     *
     * @param \FBAOutboundServiceMWS_Model_GetFulfillmentPreviewResponse $fulfillmentPreview
     *
     * @return array
     */
    protected function getRatesFromFulfillmentPreview(\FBAOutboundServiceMWS_Model_GetFulfillmentPreviewResponse $fulfillmentPreview) {
        $previews = $fulfillmentPreview->getGetFulfillmentPreviewResult()
            ->getFulfillmentPreviews()
            ->getmember();
        $rates = [];

        /** @var FBAOutboundServiceMWS_Model_FulfillmentPreview $preview */
        foreach ($previews as $preview) {
            $shipDate = 0;
            $fulfillable = $preview->getIsFulfillable();
            if ($preview->getIsFulfillable() != 'false') {
                $title = $preview->getShippingSpeedCategory();
                $shippingFee = $this->calculateShippingFee($preview->getEstimatedFees());
                $sdpreview = $preview->getFulfillmentPreviewShipments()
                    ->getmember();
                foreach ($sdpreview as $sdates) {
                    if (!empty($sdates->getLatestArrivalDate())) {
                        $shipDate = $sdates->getLatestArrivalDate();
                    }
                }

                $rates[$title] = [
                    'price' => $shippingFee,
                    'date' => $shipDate,
                ];
            }
        }

        return $rates;
    }

    /**
     * @param $fees
     *
     * @return mixed
     */
    protected function calculateShippingFee($fees) {
        $feeAmount = 0;
        if ($fees && !empty($fees->getmember())) {
            foreach ($fees->getmember() as $fee) {
                if ($fee->getName() == 'FBAPerUnitFulfillmentFee') {

                    $feeAmount += $fee->getAmount()
                        ->getValue();
                }

            }
        }

        return $feeAmount;
    }

    /**
     * @return array
     */
    public
    function getAllowedMethods() {
        return [
            'standard' => 'Standard',
            'expedited' => 'Expedited',
            'priority' => 'Priority',
        ];
    }

    /**
     * Prepare and set request to this instance
     *
     * @param \Magento\Quote\Model\Quote\Address\RateRequest $request
     *
     * @return $this
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public
    function prepareShippingRequest(\Magento\Quote\Model\Quote\Address\RateRequest $request) {
        $this->_request = $request;

        $r = [];

        if ($request->getDestCountryId()) {
            $r['CountryCode'] = $request->getDestCountryId();
        }

        if (!$r['CountryCode']) {
            $country = $this->_configHelper->getStoreCountry($request->getStoreId());
            if ($country) {
                $r['CountryCode'] = $country;
            }
        }

        if (!empty($request->getDestPostcode())) {
            $r['PostalCode'] = $request->getDestPostcode();
        }

        if ($request->getDestRegionCode()) {
            $r['StateOrProvinceCode'] = $request->getDestRegionCode();
        }

        if ($request->getDestCity() && $request->getDestCountryId() != 'JP') {
            $r['City'] = $request->getDestCity();
        }

        if ($request->getDestStreet()) {
            $r['Line1'] = $request->getDestStreet();
        }


        if (isset($r['CountryCode']) && (isset($r['PostalCode']) || isset($r['StateOrProvinceCode']))) {
            // API requires a name but all other shipping methods function without out - using placeholder.
            if (!isset($r['Name'])) {
                $r['Name'] = 'john doe';
            }

            // API requires street but all other shipping methods can function without it - using placeholder.
            if (!isset($r['Line1'])) {
                $r['Line1'] = '100 Placeholder St';
            }

            // API requires city, but all other shipping methods can function without it - using placeholder.
            if (!isset($r['City'])) {
                $r['City'] = 'Placeholderville';
            }

            if (!isset($r['StateOrProvinceCode'])) {
                $r['StateOrProvinceCode'] = 'WA';
            }

            $this->_address = $r;
        }
        else {
            $this->_address = [];
        }
    }

}
