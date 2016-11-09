<?php

class MercadoPago_Core_Block_AbstractSuccess
    extends Mage_Core_Block_Template
{

    public function getPayment()
    {
        $order = $this->getOrder();
        $payment = $order->getPayment();

        return $payment;
    }

    public function getOrder()
    {
        $orderIncrementId = Mage::getSingleton('checkout/session')->getLastRealOrderId();
        $order = Mage::getModel('sales/order')->loadByIncrementId($orderIncrementId);

        return $order;
    }

    public function getTotal()
    {
        $order = $this->getOrder();
        $total = $order->getBaseGrandTotal();

        if (!$total) {
            $total = $order->getBasePrice() + $order->getBaseShippingAmount();
        }

        $total = number_format($total, 2, '.', '');

        return $total;
    }

    public function getEntityId()
    {
        $order = $this->getOrder();

        return $order->getEntityId();
    }

    public function getPaymentMethod()
    {
        $payment_method = $this->getPayment()->getMethodInstance()->getCode();

        return $payment_method;
    }

    public function getInfoPayment()
    {
        $order_id = Mage::getSingleton('checkout/session')->getLastRealOrderId();
        $info_payments = Mage::getModel('mercadopago/core')->getInfoPaymentByOrder($order_id);

        return $info_payments;
    }

    public function getMessageByStatus($status, $statusDetail, $paymentMethod, $installment, $amount)
    {
        $status = $this->_validStatusTwoPayments($status);
        $statusDetail = $this->_validStatusTwoPayments($statusDetail);

        $message = [
            "title"   => "",
            "message" => ""
        ];

        $rawMessage = Mage::helper('mercadopago/statusMessage')->getMessage($status);
        $message['title'] = Mage::helper('mercadopago')->__($rawMessage['title']);

        if ($status == 'rejected') {
            if ($statusDetail == 'cc_rejected_invalid_installments') {
                $message['message'] = Mage::helper('mercadopago')
                    ->__(Mage::helper('mercadopago/statusDetailMessage')->getMessage($statusDetail), strtoupper($paymentMethod), $installment);
            } elseif ($statusDetail == 'cc_rejected_call_for_authorize') {
                $message['message'] = Mage::helper('mercadopago')
                    ->__(Mage::helper('mercadopago/statusDetailMessage')->getMessage($statusDetail), strtoupper($paymentMethod), $amount);
            } else {
                $message['message'] = Mage::helper('mercadopago')
                    ->__(Mage::helper('mercadopago/statusDetailMessage')->getMessage($statusDetail), strtoupper($paymentMethod));
            }
        } else {
            $message['message'] = Mage::helper('mercadopago')->__($rawMessage['message']);
        }

        return $message;
    }

    protected function _validStatusTwoPayments($status)
    {
        $arrayStatus = explode(" | ", $status);
        $statusVerif = true;
        $statusFinal = "";
        foreach ($arrayStatus as $status):

            if ($statusFinal == "") {
                $statusFinal = $status;
            } else {
                if ($statusFinal != $status) {
                    $statusVerif = false;
                }
            }
        endforeach;

        if ($statusVerif === false) {
            $statusFinal = "other";
        }

        return $statusFinal;
    }

}
