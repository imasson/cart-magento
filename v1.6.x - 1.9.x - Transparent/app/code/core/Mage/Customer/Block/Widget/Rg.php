<?php

class Mage_Customer_Block_Widget_Rg extends Mage_Customer_Block_Widget_Abstract {

    public function _construct() {
        parent::_construct();
        $this->setTemplate('customer/widget/rg.phtml');
    }

    public function isEnabled() {
        return (bool) $this->_getAttribute('rg')->getIsVisible();
    }

    public function isRequired() {
        return (bool) $this->_getAttribute('rg')->getIsRequired();
    }

    public function getCustomer() {
        return Mage::getSingleton('customer/session')->getCustomer();
    }

}
