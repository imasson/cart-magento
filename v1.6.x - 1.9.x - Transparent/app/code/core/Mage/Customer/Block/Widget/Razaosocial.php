<?php

class Mage_Customer_Block_Widget_Razaosocial extends Mage_Customer_Block_Widget_Abstract {

    public function _construct() {
        parent::_construct();
        $this->setTemplate('customer/widget/razao-social.phtml');
    }

    public function isEnabled() {
        return (bool) $this->_getAttribute('razao_social')->getIsVisible();
    }

    public function isRequired() {
        return (bool) $this->_getAttribute('razao_social')->getIsRequired();
    }
}