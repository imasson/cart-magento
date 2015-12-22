<?php

class Mage_Customer_Block_Widget_Nomefantasia extends Mage_Customer_Block_Widget_Abstract {

    public function _construct() {
        parent::_construct();
        $this->setTemplate('customer/widget/nome-fantasia.phtml');
    }

    public function isEnabled() {
        return (bool) $this->_getAttribute('nome_fantasia')->getIsVisible();
    }

    public function isRequired() {
        return (bool) $this->_getAttribute('nome_fantasia')->getIsRequired();
    }
}