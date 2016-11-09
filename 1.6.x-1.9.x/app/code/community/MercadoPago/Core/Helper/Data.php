<?php

/**
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL).
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @category       Payment Gateway
 * @package        MercadoPago
 * @author         Gabriel Matsuoka (gabriel.matsuoka@gmail.com)
 * @copyright      Copyright (c) MercadoPago [http://www.mercadopago.com]
 * @license        http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class MercadoPago_Core_Helper_Data
    extends Mage_Payment_Helper_Data
{

    const XML_PATH_ACCESS_TOKEN = 'payment/mercadopago_custom_checkout/access_token';
    const XML_PATH_PUBLIC_KEY = 'payment/mercadopago_custom_checkout/public_key';
    const XML_PATH_CLIENT_ID = 'payment/mercadopago_standard/client_id';
    const XML_PATH_CLIENT_SECRET = 'payment/mercadopago_standard/client_secret';

    const PLATFORM_V1_WHITELABEL = 'v1-whitelabel';
    const PLATFORM_DESKTOP = 'Desktop';
    const TYPE = 'magento';

    protected $_apiInstance;
    protected $_config;


    protected $_website;

    public function log($message, $file = "mercadopago.log", $array = null)
    {
        $actionLog = Mage::getStoreConfig('payment/mercadopago/logs');

        if ($actionLog) {
            if (!is_null($array)) {
                $message .= " - " . json_encode($array);
            }

            Mage::log($message, null, $file, $actionLog);
        }
    }

    /**
     * @return mixed
     */
    public function getApiInstance()
    {
        if (empty($this->_apiInstance)) {
            $params = func_num_args();
            if ($params > 2 || $params < 1) {
                Mage::throwException("Invalid arguments. Use CLIENT_ID and CLIENT SECRET, or ACCESS_TOKEN");
            }
            if ($params == 1) {
                $api = new MercadoPago_Lib_Api(func_get_arg(0));
                $api->set_platform(self::PLATFORM_V1_WHITELABEL);
            } else {
                $api = new MercadoPago_Lib_Api(func_get_arg(0), func_get_arg(1));
                $api->set_platform(self::PLATFORM_DESKTOP);
            }
            if (Mage::getStoreConfigFlag('payment/mercadopago_standard/sandbox_mode')) {
                $api->sandbox_mode(true);
            }
            $api->set_type(self::TYPE . ' ' . (string)Mage::getConfig()->getModuleConfig("MercadoPago_Core")->version);
            $this->_apiInstance = $api;
        }

        return $this->_apiInstance;
    }

    public function initApiInstance()
    {
        if (!$this->_config) {
            \MercadoPago\MercadoPagoSdk::initialize();
            $this->_config = \MercadoPago\MercadoPagoSdk::config();
        }

        $params = func_num_args();
        if (empty($params)) {
            return;
        }

        if ($params == 1) {
            $this->_config->set('ACCESS_TOKEN', func_get_arg(0));
        } else {
            $this->_config->set('CLIENT_ID', func_get_arg(0));
            $this->_config->set('CLIENT_SECRET', func_get_arg(1));
        }
    }

    public function isValidAccessToken($accessToken)
    {
        $this->initApiInstance();
        $response = \MercadoPago\MercadoPagoSdk::restClient()->get("/v1/payment_methods", ['url_query' => ['access_token' => $accessToken]]);
        if ($response['code'] == 401 || $response['code'] == 400) {
            return false;
        }
        $this->_config->set('ACCESS_TOKEN', $accessToken);

        return true;
    }

    public function isValidClientCredentials($clientId, $clientSecret)
    {
        $this->initApiInstance($clientId, $clientSecret);
        $accessToken = $this->_config->get('ACCESS_TOKEN');

        return !empty($accessToken);
    }

    public function getAccessToken()
    {
        $clientId = Mage::getStoreConfig(self::XML_PATH_CLIENT_ID);
        $clientSecret = Mage::getStoreConfig(self::XML_PATH_CLIENT_SECRET);

        if ($this->isValidClientCredentials($clientId, $clientSecret)) {
            return $this->_config->get('ACCESS_TOKEN');
        } else {
            return false;
        }
    }

    public function setOrderSubtotals($data, $order)
    {
        if (isset($data['total_paid_amount'])) {
            $balance = $this->_getMultiCardValue($data, 'total_paid_amount');
        } else {
            $balance = $data['transaction_details']['total_paid_amount'];
        }
        $shippingCost = $this->_getMultiCardValue($data, 'shipping_cost');

        $order->setGrandTotal($balance);
        $order->setBaseGrandTotal($balance);
        if ($shippingCost > 0) {
            $order->setBaseShippingAmount($shippingCost);
            $order->setShippingAmount($shippingCost);
        }

        $couponAmount = $this->_getMultiCardValue($data, 'coupon_amount');
        $transactionAmount = $this->_getMultiCardValue($data, 'transaction_amount');

        if ($couponAmount) {
            $order->setDiscountCouponAmount($couponAmount * -1);
            $order->setBaseDiscountCouponAmount($couponAmount * -1);
            $balance = $balance - ($transactionAmount - $couponAmount + $shippingCost);
        } else {
            $balance = $balance - $transactionAmount - $shippingCost;
        }

        if (!Mage::getStoreConfigFlag('payment/mercadopago/financing_cost')) {
            $order->setGrandTotal($order->getGrandTotal() - $balance);
            $order->setBaseGrandTotal($order->getBaseGrandTotal() - $balance);

            return;
        }

        if (Zend_Locale_Math::round($balance, 4) > 0) {
            $order->setFinanceCostAmount($balance);
            $order->setBaseFinanceCostAmount($balance);
        }
    }

    /**
     * @param $payment
     *
     * @return mixed
     */
    public function setPayerInfo(&$payment)
    {
        $payment["trunc_card"] = "xxxx xxxx xxxx " . $payment['card']["last_four_digits"];
        $payment["cardholder_name"] = $payment['card']["cardholder"]["name"];
        $payment['payer_first_name'] = $payment['payer']['first_name'];
        $payment['payer_last_name'] = $payment['payer']['last_name'];
        $payment['payer_email'] = $payment['payer']['email'];

        return $payment;
    }

    protected function _getMultiCardValue($data, $field)
    {
        $finalValue = 0;
        if (!isset($data[$field])) {
            return $finalValue;
        }
        $amountValues = explode('|', $data[$field]);
        $statusValues = explode('|', $data['status']);
        foreach ($amountValues as $key => $value) {
            $value = (float)str_replace(' ', '', $value);
            if (str_replace(' ', '', $statusValues[$key]) == 'approved') {
                $finalValue = $finalValue + $value;
            }
        }

        return $finalValue;
    }

    public function getSuccessUrl()
    {
        if (Mage::getStoreConfig('payment/mercadopago/use_successpage_mp')) {
            $url = 'mercadopago/success';
        } else {
            $url = 'checkout/onepage/success';
        }

        return $url;
    }

    /**
     * Return the website associated to admin combo select
     *
     * @return Mage_Core_Model_Website
     */
    public function getAdminSelectedWebsite()
    {
        if (isset($this->_website)) {
            return $this->_website;
        }

        $websiteId = Mage::getSingleton('adminhtml/config_data')->getWebsite();

        if ($websiteId) {
            $this->_website = Mage::app()->getWebsite($websiteId);
        } else {
            $this->_website = Mage::app()->getWebsite();
        }

        return $this->_website;
    }

}
