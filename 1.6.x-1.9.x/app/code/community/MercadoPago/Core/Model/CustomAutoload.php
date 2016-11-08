<?php

// @codingStandardsIgnoreFile
class MercadoPago_Core_Model_CustomAutoload
{
    /**
     */
    public function createAndRegister()
    {
        self::init();
    }

    /**
     */
    public static function init()
    {
        // Add our vendor folder to our include path
        set_include_path(get_include_path() . PATH_SEPARATOR . Mage::getBaseDir('lib') . DS . 'MercadoPago/Lib' . DS . 'vendor');

        // Include the autoloader for composer
        require_once(Mage::getBaseDir('lib') . DS . 'MercadoPago' . DS . 'Lib' . DS . 'vendor' . DS . 'autoload.php');
    }

}