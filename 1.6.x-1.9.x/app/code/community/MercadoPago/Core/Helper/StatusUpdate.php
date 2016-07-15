<?php

class MercadoPago_Core_Helper_StatusUpdate
    extends Mage_Payment_Helper_Data
{

    protected $_statusUpdatedFlag = false;


    private $_rawMessage;

    public function isStatusUpdated()
    {
        return $this->_statusUpdatedFlag;
    }

    public function setStatusUpdated($notificationData)
    {
        $order = Mage::getModel('sales/order')->loadByIncrementId($notificationData["external_reference"]);
        $status = $notificationData['status'];
        $statusDetail = $notificationData['status_detail'];
        $currentStatus = $order->getPayment()->getAdditionalInformation('status');
        if ($status == $currentStatus && $order->getState() === Mage_Sales_Model_Order::STATE_COMPLETE) {
            $this->_statusUpdatedFlag = true;
        }
    }

    protected function _updateStatus($order, $status, $message, $statusDetail)
    {
        if ($order->getState() !== Mage_Sales_Model_Order::STATE_COMPLETE) {
            $statusOrder = $this->getStatusOrder($status, $statusDetail);

            if (isset($statusOrder)) {
                $order->setState($this->_getAssignedState($statusOrder));
                $order->addStatusToHistory($statusOrder, $message, true);
                $order->sendOrderUpdateEmail(true, $message);
            }
        }
    }

    protected function _generateCreditMemo($order, $payment)
    {
        if (isset($payment['amount_refunded']) && $payment['amount_refunded'] > 0 && $payment['amount_refunded'] == $payment['total_paid_amount']) {
            $order->getPayment()->registerRefundNotification($payment['amount_refunded']);
            $creditMemo = array_pop($order->getCreditmemosCollection()->setPageSize(1)->setCurPage(1)->load()->getItems());
            foreach ($creditMemo->getAllItems() as $creditMemoItem) {
                $creditMemoItem->setBackToStock(Mage::helper('cataloginventory')->isAutoReturnEnabled());
            }
            $creditMemo->save();
            $order->cancel();
        }
    }

    protected function _update($order, $payment, $message) {
        $status = $payment['status'];
        $statusDetail = $payment['status_detail'];

        if ($status == 'approved') {
            Mage::helper('mercadopago')->setOrderSubtotals($payment, $order);
            $this->_createInvoice($order, $message);
            //Associate card to customer
            $additionalInfo = $order->getPayment()->getAdditionalInformation();
            if (isset($additionalInfo['token'])) {
                Mage::getModel('mercadopago/custom_payment')->customerAndCards($additionalInfo['token'], $payment);
            }

        } elseif ($status == 'refunded' || $status == 'cancelled') {
            //generate credit memo and return items to stock according to setting
            $this->_generateCreditMemo($order, $payment);
        }
        //if state is not complete updates according to setting
        $this->_updateStatus($order, $status, $message, $statusDetail);

        return $order->save();
    }


    public function getMessage($status, $payment)
    {
        if (!$this->_rawMessage) {
            $rawMessage = Mage::helper('mercadopago')->__(Mage::helper('mercadopago/statusOrderMessage')->getMessage($status));
            $rawMessage .= Mage::helper('mercadopago')->__('<br/> Payment id: %s', $payment['id']);
            $rawMessage .= Mage::helper('mercadopago')->__('<br/> Status: %s', $payment['status']);
            $rawMessage .= Mage::helper('mercadopago')->__('<br/> Status Detail: %s', $payment['status_detail']);
            $this->_rawMessage = $rawMessage;
        }

        return $this->_rawMessage;
    }

    public function getStatus($payment)
    {
        $status = $payment['status'];
        if (isset($payment['status_final'])) {
            $status = $payment['status_final'];
        }

        return $status;
    }


    public function getStatusOrder($status, $statusDetail)
    {
        switch ($status) {
            case 'approved': {
                $status = Mage::getStoreConfig('payment/mercadopago/order_status_approved');

                if ($statusDetail == 'partially_refunded') { //MIRAR SI canCreditMemo
                    $status = Mage::getStoreConfig('payment/mercadopago/order_status_partially_refunded');
                }
                break;
            }
            case 'refunded': {
                $status = Mage::getStoreConfig('payment/mercadopago/order_status_refunded');
                break;
            }
            case 'in_mediation': {
                $status = Mage::getStoreConfig('payment/mercadopago/order_status_in_mediation');
                break;
            }
            case 'cancelled': {
                $status = Mage::getStoreConfig('payment/mercadopago/order_status_cancelled');
                break;
            }
            case 'rejected': {
                $status = Mage::getStoreConfig('payment/mercadopago/order_status_rejected');
                break;
            }
            case 'chargeback': {
                $status = Mage::getStoreConfig('payment/mercadopago/order_status_chargeback');
                break;
            }
            default: {
                $status = Mage::getStoreConfig('payment/mercadopago/order_status_in_process');
            }
        }

        return $status;
    }

}
