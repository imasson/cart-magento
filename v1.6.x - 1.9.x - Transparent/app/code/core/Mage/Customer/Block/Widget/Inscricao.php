<?php

class Mage_Customer_Block_Widget_Inscricao extends Mage_Customer_Block_Widget_Abstract {

    public function _construct() {
        parent::_construct();
        $this->setTemplate('customer/widget/insc-est.phtml');
    }

    public function isEnabled() {
        return (bool) $this->_getAttribute('insc_est')->getIsVisible();
    }

    public function isRequired() {
        return (bool) $this->_getAttribute('insc_est')->getIsRequired();
    }

    public function getCustomer() {
        return Mage::getSingleton('customer/session')->getCustomer();
    }

}
