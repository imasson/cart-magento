<?php

class Mage_Customer_Block_Address_Mobile extends Mage_Directory_Block_Data {

    protected $_address;

    public function _construct() {
        parent::_construct();
        $this->setTemplate('customer/address/mobile.phtml');
    }

    public function isEnabled() {
        return (bool) (Mage::getStoreConfig(Ideasa_IdeCheckoutvm_ConfiguracoesSystem::MOBILE) != Ideasa_IdeCheckoutvm_Block_Widget_Address_Address::NO);
    }

    public function isRequired() {
        return (bool) (Mage::getStoreConfig(Ideasa_IdeCheckoutvm_ConfiguracoesSystem::MOBILE) == Ideasa_IdeCheckoutvm_Block_Widget_Address_Address::REQ);
    }

    public function setAddress($address) {
        $this->_address = $address;
        return $this;
    }

    public function getAddress() {
        return $this->_address;
    }
}