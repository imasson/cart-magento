<?php

class MercadoPago_Core_Model_Custom_Finance_Cost
    extends Mage_Sales_Model_Quote_Address_Total_Abstract
{

    protected $_code = 'financing_cost';

    public function collect(Mage_Sales_Model_Quote_Address $address)
    {
        if ($this->_getFinancingCondition($address)) {

            $amt = Mage::app()->getRequest()->getPost();
            parent::collect($address);

            $balance = $amt['total_amount'] - $amt['amount'];
            $address->setFinanceCostAmount($balance);
            $address->setBaseFinanceCostAmount($balance);

            $this->_setAmount($balance);
            $this->_setBaseAmount($balance);

        }

        return $this;
    }

    public function fetch(Mage_Sales_Model_Quote_Address $address)
    {
        if ($this->_getFinancingCondition($address)) {
            $address->addTotal(array(
                'code'  => $this->getCode(),
                'title' => Mage::helper('mercadopago')->__('Financing Cost'),
                'value' => $address->getFinanceCostAmount()
            ));
        }

        return $this;
    }

    protected function _getFinancingCondition($address)
    {
        $req = Mage::app()->getRequest()->getParam('total_amount');

        return (!empty($req) && $address->getAddressType() == 'shipping');

    }
}