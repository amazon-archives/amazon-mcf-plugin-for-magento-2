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

namespace Amazon\MCF\Helper;

use \Magento\Framework\App\Helper\AbstractHelper;

/**
 * Provides helper methods for converting Magento 2 data structures into
 * structures that can be used by Amazon MCF API
 *
 * Class Conversion
 *
 * @package Amazon\MCF\Helper
 */
class Conversion extends AbstractHelper
{

    /**
     * @var \Magento\Framework\App\ObjectManager
     */
    private $objectManager;

    /**
     * List of mail carriers
     *
     * @var array
     */
    private $carriers = [
        'USPS' => [
            'carrier_code' => 'usps',
            'title' => 'United States Postal Service',
        ],
        'UPS' => ['carrier_code' => 'ups', 'title' => 'United Parcel Service'],
        'UPSM' => ['carrier_code' => 'ups', 'title' => 'United Parcel Service'],
        'DHL' => ['carrier_code' => 'dhl', 'title' => 'DHL'],
        'FEDEX' => ['carrier_code' => 'fedex', 'title' => 'Federal Express'],
    ];

    /**
     * Conversion constructor.
     *
     * @param \Magento\Framework\App\Helper\Context $context
     */
    public function __construct(\Magento\Framework\App\Helper\Context $context)
    {
        parent::__construct($context);

        $om = \Magento\Framework\App\ObjectManager::getInstance();
        $reader = $om->get('Magento\Framework\Module\Dir\Reader');
        $modulePath = $reader->getModuleDir('', 'Amazon_MCF');
        $this->objectManager = $om;

        $path = $modulePath . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'Amazon' . DIRECTORY_SEPARATOR
            . 'FBAOutboundServiceMWS' . DIRECTORY_SEPARATOR . 'Model' . DIRECTORY_SEPARATOR . 'Address.php';
        include_once $path;

        $path = $modulePath . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'Amazon' . DIRECTORY_SEPARATOR
            . 'FBAOutboundServiceMWS' . DIRECTORY_SEPARATOR . 'Model' . DIRECTORY_SEPARATOR
            . 'GetFulfillmentPreviewItem.php';
        include_once $path;

        $path = $modulePath . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'Amazon' . DIRECTORY_SEPARATOR
            . 'FBAOutboundServiceMWS' . DIRECTORY_SEPARATOR . 'Model' . DIRECTORY_SEPARATOR
            . 'GetFulfillmentPreviewItemList.php';
        include_once $path;
    }

    /**
     * @param \Magento\Quote\Model\Quote\Address $address
     *
     * @return \Amazon\MCF\Helper\FBAOutboundServiceMWS_Model_Address
     */
    public function getAmazonAddress(\Magento\Quote\Model\Quote\Address $address)
    {
        $amazonAddress = new FBAOutboundServiceMWS_Model_Address($this->getAmazonAddressArray($address));
        return $amazonAddress;
    }

    /**
     * Prepares a Magento address item for use by Amazon MCF
     *
     * @param \Magento\Quote\Model\Quote\Address $address
     *
     * @return array
     */
    public function getAmazonAddressArray(\Magento\Framework\DataObject $address)
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $region = $objectManager->create('Magento\Directory\Model\Region')
            ->load($address->getRegionId());

        $address = [
            'Name' => $address->getName(),
            'Line1' => $address->getStreetLine(1),
            'Line2' => $address->getStreetLine(2),
            'Line3' => $address->getStreetLine(3),
            'DistrictOrCounty' => $address->getCountry(),
            'City' => $address->getCity(),
            'StateOrProvinceCode' => $region->getCode(),
            'CountryCode' => $address->getCountryId(),
            'PostalCode' => $address->getPostcode(),
            'PhoneNumber' => $address->getTelephone(),
        ];

        return $address;
    }

    /**
     * Prepares item data from Magento 2 order for use with Amazon MCF API
     *
     * @param array $items
     *
     * @return array
     */
    public function getAmazonItemsArrayFromRateRequest(array $items)
    {
        $data = [];

        foreach ($items as $item) {
            $enabled = $item->getProduct()->getData('amazon_mcf_asin_enabled');
            $sku = $item->getProduct()->getData('amazon_mcf_merchant_sku');
            if ($enabled && $item->canShip()) {
                $qty = 0;
                $id = '';

                !empty($item->getQtyToShip()) ? $qty = $item->getQtyToShip() : $qty = $item->getQtyOrdered();
                !empty($item->getQuoteId()) ? $id = $item->getQuoteId() : $id = $item->getQuoteItemId();

                $itemData = [
                    'SellerSKU' => $sku ? $sku : $item->getSku(),
                    'SellerFulfillmentOrderItemId' => $id,
                    'Quantity' => $qty,
                ];
                $data[] = $itemData;
            }
        }

        if (!empty($data)) {
            return ['member' => $data];
        }

        return $data;
    }

    /**
     * Returns ISO timestamp from a formatted date string.
     *
     * @param $timestamp
     *
     * @return false|string
     */
    public function getIso8601Timestamp($timestamp)
    {
        $timestamp = strtotime($timestamp);
        $converted = date('Y-m-d\TH:i:s.Z\Z', $timestamp);
        return $converted;
    }

    /**
     * Returns simple string name of amazon shipping method.
     *
     * @param $shippingMethod
     *
     * @return mixed|null
     */
    public function getShippingSpeed($shippingMethod)
    {
        $methods = [
            'amazonfulfillment_standard' => 'Standard',
            'amazonfulfillment_priority' => 'Priority',
            'amazonfulfillment_expedited' => 'Expedited',
            'tablerate_bestway' => 'Standard',
            'freeshipping_freeshipping' => 'Standard',
            'flatrate_flatrate' => 'Standard',
        ];

        if (isset($methods[$shippingMethod])) {
            return $methods[$shippingMethod];
        }

        return null;
    }

    /**
     * @param $package
     *
     * @return mixed
     */
    public function getCarrierCodeFromPackage($package)
    {
        $code = $package->getCarrierCode();
        if (isset($this->carriers[$code]) && isset($this->carriers[$code]['carrier_code'])) {
            return $this->carriers[$code]['carrier_code'];
        }
        return strtolower(str_ireplace(' ', '_', $code));
    }

    /**
     * @param $package
     *
     * @return mixed
     */
    public function getCarrierTitleFromPackage($package)
    {
        $code = $package->getCarrierCode();
        if (isset($this->carriers[$code]) && isset($this->carriers[$code]['title'])) {
            return $this->carriers[$code]['title'];
        }
        return $code;
    }
}
