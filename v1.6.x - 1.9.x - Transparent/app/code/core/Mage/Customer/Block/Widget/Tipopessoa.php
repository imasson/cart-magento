<?php

class Mage_Customer_Block_Widget_Tipopessoa extends Mage_Customer_Block_Widget_Abstract {

    /**
     * Initialize block
     */
    public function _construct() {
        parent::_construct();
        $this->setTemplate('customer/widget/tipo-pessoa.phtml');
    }

    /**
     * Check if gender attribute enabled in system
     *
     * @return bool
     */
    public function isEnabled() {
        return (bool) $this->_getAttribute('tipo_pessoa')->getIsVisible();
    }

    /**
     * Check if gender attribute marked as required
     *
     * @return bool
     */
    public function isRequired() {
        return (bool) $this->_getAttribute('tipo_pessoa')->getIsRequired();
    }

    /**
     * Get current customer from session
     *
     * @return Mage_Customer_Model_Customer
     */
    public function getCustomer() {
        return Mage::getSingleton('customer/session')->getCustomer();
    }

}