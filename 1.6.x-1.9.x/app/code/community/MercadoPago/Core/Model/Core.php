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
class MercadoPago_Core_Model_Core
    extends Mage_Payment_Model_Method_Abstract
{
    protected $_code = 'mercadopago';
    protected $_accessToken;
    protected $_clientId;
    protected $_clientSecret;

    protected $_isGateway = true;
    protected $_canOrder = true;
    protected $_canRefund = true;
    protected $_canVoid = true;
    protected $_canUseInternal = true;
    protected $_canUseCheckout = true;
    protected $_canUseForMultishipping = true;
    protected $_canFetchTransactionInfo = true;
    protected $_canCreateBillingAgreement = true;
    protected $_canReviewPayment = true;

    const XML_PATH_ACCESS_TOKEN = 'payment/mercadopago_custom_checkout/access_token';
    const LOG_FILE = 'mercadopago-custom.log';

    /**
     * @return Mage_Checkout_Model_Session
     */
    protected function _getCheckout()
    {
        return Mage::getSingleton('checkout/session');
    }

    /**
     * Get admin checkout session namespace
     *
     * @return Mage_Adminhtml_Model_Session_Quote
     */
    protected function _getAdminCheckout()
    {
        return Mage::getSingleton('adminhtml/session_quote');
    }

    /**
     * Retrieves Quote
     *
     * @param integer $quoteId
     *
     * @return Mage_Sales_Model_Quote
     */
    protected function _getQuote($quoteId = null)
    {
        if (!empty($quoteId)) {
            return Mage::getModel('sales/quote')->load($quoteId);
        } else {
            if (Mage::app()->getStore()->isAdmin()) {
                return $this->_getAdminCheckout()->getQuote();
            } else {
                return $this->_getCheckout()->getQuote();
            }
        }
    }

    /**
     * Retrieves Order
     *
     * @param integer $incrementId
     *
     * @return Mage_Sales_Model_Order
     */
    protected function _getOrder($incrementId)
    {
        return Mage::getModel('sales/order')->loadByIncrementId($incrementId);
    }

    public function getInfoPaymentByOrder($orderId)
    {
        $order = Mage::getModel('sales/order')->loadByIncrementId($orderId);
        $payment = $order->getPayment();
        $infoPayments = [];
        $fields = [
            ["field" => "cardholderName", "title" => "Card Holder Name: %s"],
            ["field" => "trunc_card", "title" => "Card Number: %s"],
            ["field" => "payment_method", "title" => "Payment Method: %s"],
            ["field" => "expiration_date", "title" => "Expiration Date: %s"],
            ["field" => "installments", "title" => "Installments: %s"],
            ["field" => "statement_descriptor", "title" => "Statement Descriptor: %s"],
            ["field" => "payment_id", "title" => "Payment id (MercadoPago): %s"],
            ["field" => "status", "title" => "Payment Status: %s"],
            ["field" => "status_detail", "title" => "Payment Detail: %s"],
            ["field" => "activation_uri", "title" => "Generate Ticket"],
            ["field" => "payment_id_detail", "title" => "Mercado Pago Payment Id: %s"],
        ];

        foreach ($fields as $field) {
            if ($payment->getAdditionalInformation($field['field']) != "") {
                $text = Mage::helper('mercadopago')->__($field['title'], $payment->getAdditionalInformation($field['field']));
                $infoPayments[$field['field']] = [
                    "text"  => $text,
                    "value" => $payment->getAdditionalInformation($field['field'])
                ];
            }
        }

        return $infoPayments;
    }

    protected function getCustomerInfo($customer, $order)
    {
        $email = htmlentities($customer->getEmail());
        if ($email == "") {
            $email = $order['customer_email'];
        }

        $firstName = htmlentities($customer->getFirstname());
        if ($firstName == "") {
            $firstName = $order->getBillingAddress()->getFirstname();
        }

        $lastName = htmlentities($customer->getLastname());
        if ($lastName == "") {
            $lastName = $order->getBillingAddress()->getLastname();
        }

        return ['email' => $email, 'first_name' => $firstName, 'last_name' => $lastName];
    }

    protected function getItemsInfo($order)
    {
        $dataItems = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $product = $item->getProduct();
            $image = (string)Mage::helper('catalog/image')->init($product, 'image');

            $dataItems[] = [
                "id"          => $item->getSku(),
                "title"       => $product->getName(),
                "description" => $product->getName(),
                "picture_url" => $image,
                "category_id" => Mage::getStoreConfig('payment/mercadopago/category_id'),
                "quantity"    => (int)number_format($item->getQtyOrdered(), 0, '.', ''),
                "unit_price"  => (float)number_format($product->getPrice(), 2, '.', '')
            ];
        }

        /* verify discount and add it like an item */
        $discount = $this->getDiscount();
        if ($discount != 0) {
            $dataItems[] = [
                "title"       => "Discount by the Store",
                "description" => "Discount by the Store",
                "quantity"    => 1,
                "unit_price"  => (float)number_format($discount, 2, '.', '')
            ];
        }

        return $dataItems;

    }

    protected function getCouponInfo($coupon, $couponCode)
    {
        $infoCoupon = [];
        $infoCoupon['coupon_amount'] = (float)$coupon['response']['coupon_amount'];
        $infoCoupon['coupon_code'] = $couponCode;
        $infoCoupon['campaign_id'] = $coupon['response']['id'];
        if ($coupon['status'] == 200) {
            Mage::helper('mercadopago')->log("Coupon applied. API response 200.", self::LOG_FILE);
        } else {
            Mage::helper('mercadopago')->log("Coupon invalid, not applied.", self::LOG_FILE);
        }

        return $infoCoupon;
    }

    public function makeDefaultPreferencePaymentV1($paymentInfo = [])
    {
        $quote = $this->_getQuote();
        $orderId = $quote->getReservedOrderId();
        $order = $this->_getOrder($orderId);
        $customer = Mage::getSingleton('customer/session')->getCustomer();

        $billingAddress = $quote->getBillingAddress()->getData();
        $customerInfo = $this->getCustomerInfo($customer, $order);

        /* INIT PREFERENCE */
        $preference = [];

        $preference['notification_url'] = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK) . "mercadopago/notifications/custom";
        $preference['description'] = Mage::helper('mercadopago')->__("Order # %s in store %s", $orderId, Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK, true));
        $preference['transaction_amount'] = (float)$this->getAmount();

        $preference['external_reference'] = $orderId;
        $preference['payer']['email'] = $customerInfo['email'];

        if (!empty($paymentInfo['identification_type'])) {
            $preference['payer']['identification']['type'] = $paymentInfo['identification_type'];
            $preference['payer']['identification']['number'] = $paymentInfo['identification_number'];
        }
        $preference['additional_info']['items'] = $this->getItemsInfo($order);

        $preference['additional_info']['payer']['first_name'] = $customerInfo['first_name'];
        $preference['additional_info']['payer']['last_name'] = $customerInfo['last_name'];

        $preference['additional_info']['payer']['address'] = [
            "zip_code"      => $billingAddress['postcode'],
            "street_name"   => $billingAddress['street'] . " - " . $billingAddress['city'] . " - " . $billingAddress['country_id'],
            "street_number" => ''
        ];

        $preference['additional_info']['payer']['registration_date'] = date('Y-m-d', $customer->getCreatedAtTimestamp()) . "T" . date('H:i:s', $customer->getCreatedAtTimestamp());

        if ($order->canShip()) {
            $shippingAddress = $order->getShippingAddress();
            $shipping = $shippingAddress->getData();

            $preference['additional_info']['shipments']['receiver_address'] = [
                "zip_code"      => $shipping['postcode'],
                "street_name"   => $shipping['street'] . " - " . $shipping['city'] . " - " . $shipping['country_id'],
                "street_number" => '',
                "floor"         => "-",
                "apartment"     => "-",

            ];
        }

        $preference['additional_info']['payer']['phone'] = [
            "area_code" => "0",
            "number"    => $billingAddress['telephone']
        ];

        if (!empty($paymentInfo['coupon_code'])) {
            $couponCode = $paymentInfo['coupon_code'];
            Mage::helper('mercadopago')->log("Validating coupon_code: " . $couponCode, self::LOG_FILE);

            $coupon = $this->validCoupon($couponCode);
            Mage::helper('mercadopago')->log("Response API Coupon: ", self::LOG_FILE, $coupon);

            $couponInfo = $this->getCouponInfo($coupon, $couponCode);
            $preference['coupon_amount'] = $couponInfo['coupon_amount'];
            $preference['coupon_code'] = strtoupper($couponInfo['coupon_code']);
            $preference['campaign_id'] = $couponInfo['campaign_id'];

        }

        $sponsorId = Mage::getStoreConfig('payment/mercadopago/sponsor_id');
        Mage::helper('mercadopago')->log("Sponsor_id", 'mercadopago-standard.log', $sponsorId);
        if (!empty($sponsorId)) {
            Mage::helper('mercadopago')->log("Sponsor_id identificado", self::LOG_FILE, $sponsorId);
            $preference['sponsor_id'] = (int)$sponsorId;
        }

        return $preference;
    }


    public function postPaymentV1($preference)
    {
        if (!$this->_accessToken) {
            $this->_accessToken = Mage::getStoreConfig(self::XML_PATH_ACCESS_TOKEN);
        }
        Mage::helper('mercadopago')->log("Access Token for Post", self::LOG_FILE, $this->_accessToken);

        //set sdk php mercadopago
        Mage::helper('mercadopago')->initApiInstance($this->_accessToken);
        
        $preference = new \MercadoPago\Payment($preference);
        $response = $preference->save();
        
        Mage::helper('mercadopago')->log("POST /v1/payments", self::LOG_FILE, $response);

        if ($response['code'] == 200 || $response['code'] == 201) {
            return $preference;
        } else {
            $e = "";
            $exception = new MercadoPago_Core_Model_Api_V1_Exception();
            if (count($response['body']['cause']) > 0) {
                foreach ($response['body']['cause'] as $error) {
                    $e .= $exception->getUserMessage($error) . " ";
                }
            } else {
                $e = $exception->getUserMessage();
            }

            Mage::helper('mercadopago')->log("error post pago: " . $e, self::LOG_FILE);
            Mage::helper('mercadopago')->log("response post pago: ", self::LOG_FILE, $response);

            $exception->setMessage($e);
            throw $exception;
        }
    }

    public function getPayment($paymentId)
    {
        if (!$this->_clientId || !$this->_clientSecret) {
            $this->_clientId = Mage::getStoreConfig(MercadoPago_Core_Helper_Data::XML_PATH_CLIENT_ID);
            $this->_clientSecret = Mage::getStoreConfig(MercadoPago_Core_Helper_Data::XML_PATH_CLIENT_SECRET);
        }
        Mage::helper('mercadopago')->initApiInstance($this->_clientId, $this->_clientSecret);
        $payment = new \MercadoPago\Payment();
        $payment->id = $paymentId;
        return $payment->read();
    }

    public function getPaymentV1($paymentId)
    {
        if (!$this->_accessToken) {
            $this->_accessToken = Mage::getStoreConfig(self::XML_PATH_ACCESS_TOKEN);
        }
        Mage::helper('mercadopago')->initApiInstance($this->_accessToken);

        return  \MercadoPago\MercadoPagoSdk::restClient()->get("/v1/payments/" . $paymentId, ['url_query' => ['access_token' => $this->_accessToken]]);
    }

    public function getMerchantOrder($merchantOrderId)
    {
        if (!$this->_clientId || !$this->_clientSecret) {
            $this->_clientId = Mage::getStoreConfig(MercadoPago_Core_Helper_Data::XML_PATH_CLIENT_ID);
            $this->_clientSecret = Mage::getStoreConfig(MercadoPago_Core_Helper_Data::XML_PATH_CLIENT_SECRET);
        }
        Mage::helper('mercadopago')->initApiInstance($this->_clientId, $this->_clientSecret);

        $at = \MercadoPago\MercadoPagoSdk::config()->get('ACCESS_TOKEN');
        return \MercadoPago\MercadoPagoSdk::restClient()->get("/merchant_orders/" . $merchantOrderId, ['url_query' => ['access_token' => $at]]);
    }

    public function getPaymentMethods()
    {
        if (!$this->_accessToken) {
            $this->_accessToken = Mage::getStoreConfig(self::XML_PATH_ACCESS_TOKEN);
        }

        Mage::helper('mercadopago')->initApiInstance($this->_accessToken);

        $paymentMethods = new \MercadoPago\PaymentMethod();
        $paymentMethods = $paymentMethods->loadAll();
        return $paymentMethods;
    }

    public function getEmailCustomer()
    {
        $customer = Mage::getSingleton('customer/session')->getCustomer();
        $email = $customer->getEmail();

        if (empty($email)) {
            $quote = $this->_getQuote();
            $email = $quote->getBillingAddress()->getEmail();
        }

        return $email;
    }


    public function getAmount()
    {
        $quote = $this->_getQuote();
        $total = $quote->getBaseSubtotalWithDiscount() + $quote->getShippingAddress()->getShippingAmount() + $quote->getShippingAddress()->getBaseTaxAmount();

        return (float)$total;

    }

    public function validCoupon($id)
    {
        if (!$this->_accessToken) {
            $this->_accessToken = Mage::getStoreConfig(self::XML_PATH_ACCESS_TOKEN);
        }

        Mage::helper('mercadopago')->initApiInstance($this->_accessToken);

        $params = [
            'transaction_amount' => $this->getAmount(),
            'payer_email'        => $this->getEmailCustomer(),
            'coupon_code'        => $id,
            'access_token' => $this->_accessToken
        ];

        $detailsDiscount = \MercadoPago\MercadoPagoSdk::restClient()->get('/discount_campaigns', ['url_query' => $params]);

        //add value on return api discount
        $detailsDiscount['body']['transaction_amount'] = $params['transaction_amount'];
        $detailsDiscount['body']['params'] = $params;


        return $detailsDiscount;
    }

}
