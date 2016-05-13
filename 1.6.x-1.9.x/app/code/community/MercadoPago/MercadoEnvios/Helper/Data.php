<?php

class MercadoPago_MercadoEnvios_Helper_Data
    extends Mage_Core_Helper_Abstract
{

    const XML_PATH_ATTRIBUTES_MAPPING = 'carriers/mercadoenvios/attributesmapping';
    const ME_LENGTH_UNIT = 'cm';
    const ME_WEIGHT_UNIT = 'gr';
    const ME_SHIPMENT_URL = 'https://api.mercadolibre.com/shipments/';
    const ME_SHIPMENT_LABEL_URL = 'https://api.mercadolibre.com/shipment_labels';
    const ME_SHIPMENT_TRACKING_URL = 'https://api.mercadolibre.com/sites/';

    protected $_mapping;
    protected $_products = [];

    public static $enabled_methods = ['mla', 'mlb', 'mlm'];


    protected $_maxWeight = ['mla' => '25000', 'mlb' => '30000', 'mlm' => ''];
    protected $_individualDimensions = ['height' => ['mla' => ['min' => '0', 'max' => '70'], 'mlb' => ['min' => '2', 'max' => '105'], 'mlm' => ['min' => '0', 'max' => '80']],
                                        'width'  => ['mla' => ['min' => '0', 'max' => '70'], 'mlb' => ['min' => '11', 'max' => '105'], 'mlm' => ['min' => '0', 'max' => '80']],
                                        'length' => ['mla' => ['min' => '0', 'max' => '70'], 'mlb' => ['min' => '16', 'max' => '105'], 'mlm' => ['min' => '0', 'max' => '120']],
                                        'weight' => ['mla' => ['min' => '0', 'max' => '25000'], 'mlb' => ['min' => '0', 'max' => '30000'], 'mlm' => ['min' => '0', 'max' => '70000']],
    ];
    protected $_globalMaxDimensions = ['mla' => '210',
                                       'mlb' => '200',
                                       'mlm' => '347',
    ];

    /**
     * @param $quote Mage_Sales_Model_Quote
     */
    public function getDimensions($items)
    {
        $width = 0;
        $height = 0;
        $length = 0;
        $weight = 0;
        $bulk = 0;
        $helperItem = Mage::helper('mercadopago_mercadoenvios/itemData');
        //$helperCarrier = Mage::helper('mercadopago_mercadoenvios/carrierData');
        foreach ($items as $item) {
            $tempWidth = $this->_getShippingDimension($item, 'width');
            $tempHeight = $this->_getShippingDimension($item, 'height');
            $tempLength = $this->_getShippingDimension($item, 'length');
            $tempWeight = $this->_getShippingDimension($item, 'weight');
            $qty = $helperItem->itemGetQty($item);
            $bulk += ($tempWidth * $tempHeight * $tempLength) * $qty;
            $width += $tempWidth * $qty;
            $height += $tempHeight * $qty;
            $length += $tempLength * $qty;
            $weight += $tempWeight * $qty;
        }
        $height = ceil($height);
        $width = ceil($width);
        $length = ceil($length);
        $weight = ceil($weight);

        $this->validateCartDimension($height, $width, $length, $weight);
        $bulk = ceil(pow($bulk, 1/3));

        return $bulk . 'x' . $bulk . 'x' . $bulk . ',' . $weight;

    }

    /**
     * @param $item Mage_Sales_Model_Quote_Item
     */
    public function _getShippingDimension($item, $type)
    {
        $attributeMapped = $this->_getConfigAttributeMapped($type);
        if (!empty($attributeMapped)) {
            if (!isset($this->_products[$item->getProductId()])) {
                $this->_products[$item->getProductId()] = Mage::getModel('catalog/product')->load($item->getProductId());
            }
            $product = $this->_products[$item->getProductId()];
            $result = $product->getData($attributeMapped);
            $result = $this->getAttributesMappingUnitConversion($type, $result);
            $this->validateProductDimension($result, $type, $item);

            return $result;
        }

        return 0;
    }

    protected function validateProductDimension($dimension, $type, $item)
    {
        $helper = Mage::helper('mercadopago_mercadoenvios');
        $country = Mage::getStoreConfig('payment/mercadopago/country');
        if (empty((int)$dimension) || $dimension > $this->_individualDimensions[$type][$country]['max'] || $dimension < $this->_individualDimensions[$type][$country]['min']) {
            $helper->log('Invalid dimension product: PRODUCT ', $item->getData());
            Mage::throwException('Invalid dimensions product');
        }
    }

    public function validateCartDimension($height, $width, $length, $weight)
    {
        $country = Mage::getStoreConfig('payment/mercadopago/country');
        if (!isset($this->_globalMaxDimensions[$country])) {
            return;
        }
        $helper = Mage::helper('mercadopago_mercadoenvios');
        if (($height + $width + $length) > $this->_globalMaxDimensions[$country]) {
            $helper->log('Invalid dimensions in cart:', ['width' => $width, 'height' => $height, 'length' => $length, 'weight' => $weight,]);
            Mage::throwException('Invalid dimensions cart');
        }
    }

    protected function _getConfigAttributeMapped($type)
    {
        return (isset($this->getAttributeMapping()[$type]['code'])) ? $this->getAttributeMapping()[$type]['code'] : null;
    }

    public function getAttributeMapping()
    {
        if (empty($this->_mapping)) {
            $mapping = Mage::getStoreConfig(self::XML_PATH_ATTRIBUTES_MAPPING);
            $mapping = unserialize($mapping);
            $mappingResult = [];
            foreach ($mapping as $key => $map) {
                $mappingResult[$key] = ['code' => $map['attribute_code'], 'unit' => $map['unit']];
            }
            $this->_mapping = $mappingResult;
        }

        return $this->_mapping;
    }

    /**
     * @param $attributeType string
     * @param $value         string
     *
     * @return string
     */
    public function getAttributesMappingUnitConversion($attributeType, $value)
    {
        $this->_getConfigAttributeMapped($attributeType);

        if ($attributeType == 'weight') {
            //check if needs conversion
            if ($this->_mapping[$attributeType]['unit'] != self::ME_WEIGHT_UNIT) {
                $unit = new Zend_Measure_Weight((float)$value);
                $unit->convertTo(Zend_Measure_Weight::GRAM);

                return $unit->getValue();
            }

        } elseif ($this->_mapping[$attributeType]['unit'] != self::ME_LENGTH_UNIT) {
            $unit = new Zend_Measure_Length((float)$value);
            $unit->convertTo(Zend_Measure_Length::CENTIMETER);

            return $unit->getValue();
        }

        return $value;
    }

    /**
     * @return Mage_Sales_Model_Quote
     */
    public function getQuote()
    {
        if (Mage::app()->getStore()->isAdmin()) {
            $quote = Mage::getSingleton('adminhtml/session_quote')->getQuote();
        } else {
            $quote = Mage::getModel('checkout/cart')->getQuote();
        }

        return $quote;
    }

    public function isMercadoEnviosMethod($method)
    {
        $shippingMethod = substr($method, 0, strpos($method, '_'));

        return ($shippingMethod == MercadoPago_MercadoEnvios_Model_Shipping_Carrier_MercadoEnvios::CODE);
    }



    public function getFreeMethod($request)
    {
        $freeMethod = Mage::getStoreConfig('carriers/mercadoenvios/free_method');
        if (!empty($freeMethod)) {
            if (!Mage::getStoreConfigFlag('carriers/mercadoenvios/free_shipping_enable')) {
                return $freeMethod;
            } else {
                if (Mage::getStoreConfig('carriers/mercadoenvios/free_shipping_subtotal') <= $request->getPackageValue()) {
                    return $freeMethod;
                }
            }
        }

        return null;
    }

    public function isCountryEnabled()
    {
        return (in_array(Mage::getStoreConfig('payment/mercadopago/country'), self::$enabled_methods));
    }

    public function getTrackingUrlByShippingInfo($_shippingInfo)
    {
        $tracking = Mage::getModel('sales/order_shipment_track');
        $tracking = $tracking->getCollection()
            ->addFieldToFilter(
                ['entity_id', 'parent_id', 'order_id'],
                [
                    ['eq' => $_shippingInfo->getTrackId()],
                    ['eq' => $_shippingInfo->getShipId()],
                    ['eq' => $_shippingInfo->getOrderId()],
                ]
            )
            ->setPageSize(1)
            ->setCurPage(1)
            ->load();

        foreach ($_shippingInfo->getTrackingInfo() as $track) {
            $lastTrack = array_pop($track);
            if (isset($lastTrack['title']) && $lastTrack['title'] == MercadoPago_MercadoEnvios_Model_Observer::CODE) {
                $item = array_pop($tracking->getItems());
                if ($item->getId()) {
                    return $item->getDescription();
                }
            }
        }

        return '';
    }

    public function getTrackingPrintUrl($shipmentId)
    {
        if ($shipmentId) {
            if ($shipment = Mage::getModel('sales/order_shipment')->load($shipmentId)) {
                if ($shipment->getShippingLabel()) {
                    $params = [
                        'shipment_ids'  => $shipment->getShippingLabel(),
                        'response_type' => Mage::getStoreConfig('carriers/mercadoenvios/shipping_label'),
                        'access_token'  => Mage::helper('mercadopago')->getAccessToken()
                    ];

                    return self::ME_SHIPMENT_LABEL_URL . '?' . http_build_query($params);
                }
            }
        }

        return '';
    }

    public function getShipmentInfo($shipmentId)
    {
        $client = new Varien_Http_Client(self::ME_SHIPMENT_URL . $shipmentId);
        $client->setMethod(Varien_Http_Client::GET);
        $client->setParameterGet('access_token', Mage::helper('mercadopago')->getAccessToken());

        try {
            $response = $client->request();
        } catch (Exception $e) {
            $this->log($e);
            throw new Exception($e);
        }

        return json_decode($response->getBody());
    }

    public function getServiceInfo($serviceId, $country)
    {
        $client = new Varien_Http_Client(self::ME_SHIPMENT_TRACKING_URL . $country . '/shipping_services');
        $client->setMethod(Varien_Http_Client::GET);
        try {
            $response = $client->request();
        } catch (Exception $e) {
            $this->log($e);
            throw new Exception($e);
        }

        $response = json_decode($response->getBody());
        foreach ($response as $result) {
            if ($result->id == $serviceId) {
                return $result;
            }
        }

        return '';
    }

    public function log($message, $array = null, $level = Zend_Log::ERR, $file = "mercadoenvios.log")
    {
        $actionLog = Mage::getStoreConfig('carriers/mercadoenvios/log');
        if ($actionLog) {
            if (!is_null($array)) {
                $message .= " - " . json_encode($array);
            }

            Mage::log($message, $level, $file, $actionLog);
        }
    }

    /**
     * Return items for further shipment rate evaluation. We need to pass children of a bundle instead passing the
     * bundle itself, otherwise we may not get a rate at all (e.g. when total weight of a bundle exceeds max weight
     * despite each item by itself is not)
     *
     * @return array
     */
    public function getAllItems($allItems)
    {
        $items = array();
        foreach ($allItems as $item) {
            /* @var $item Mage_Sales_Model_Quote_Item */
            if ($item->getProduct()->isVirtual() || $item->getParentItem()) {
                // Don't process children here - we will process (or already have processed) them below
                continue;
            }

            if ($item->getHasChildren() && $item->isShipSeparately()) {
                foreach ($item->getChildren() as $child) {
                    if (!$child->getFreeShipping() && !$child->getProduct()->isVirtual()) {
                        $items[] = $child;
                    }
                }
            } else {
                // Ship together - count compound item as one solid
                $items[] = $item;
            }
        }

        return $items;
    }
}