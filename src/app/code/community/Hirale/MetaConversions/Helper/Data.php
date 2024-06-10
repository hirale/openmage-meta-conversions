<?php

use FacebookAds\Object\ServerSide\ActionSource;
use FacebookAds\Object\ServerSide\Content;
use FacebookAds\Object\ServerSide\Gender;

class Hirale_MetaConversions_Helper_Data extends Mage_Core_Helper_Abstract
{
    protected $_isConversionsEnabled = null;
    protected $_accessToken = null;
    protected $_pixelId = null;
    protected $_isDebugMode = null;
    protected $session;

    public function __construct()
    {
        $this->session = Mage::getSingleton('core/cookie');
    }
    public function isConversionsEnabled()
    {
        if (is_null($this->_isConversionsEnabled)) {
            $this->_isConversionsEnabled = Mage::getStoreConfig('meta/conversions/enabled');
        }
        return $this->_isConversionsEnabled;
    }

    public function isDebugMode(){
        if (is_null($this->_isDebugMode)) {
            $this->_isDebugMode = Mage::getStoreConfig('meta/conversions/debug_mode');
        }
        return $this->_isDebugMode;
    }

    public function getAccessToken()
    {
        if (is_null($this->_accessToken)) {
            $this->_accessToken = Mage::getStoreConfig('meta/conversions/access_token');
        }
        return $this->_accessToken;
    }

    public function getPixelId()
    {
        if (is_null($this->_pixelId)) {
            $this->_pixelId = Mage::getStoreConfig('meta/conversions/pixel_id');
        }
        return $this->_pixelId;
    }

    public function getEventId()
    {
        return uniqid();
    }

    public function formatPrice($price)
    {
        return (float) number_format($price, 2, '.', '');
    }

    public function prepareUserData($customer = null)
    {
        $userData = [];
        $userData['client_ip_address'] = Mage::helper('core/http')->getRemoteAddr();
        $userData['client_user_agent'] = Mage::helper('core/http')->getHttpUserAgent();
        $userData['fbp'] = $this->session->get('_fbp') ?? '';
        $userData['fbc'] = $this->session->get('_fbc') ?? '';

        if (!$customer && Mage::getSingleton('customer/session')->isLoggedIn()) {
            $customer = Mage::getSingleton('customer/session')->getCustomer();
        }
        if ($customer) {
            $address = $customer->getDefaultBillingAddress();
            $userData['email'] = $customer->getEmail();
            $userData['first_name'] = $customer->getFirstname();
            $userData['last_name'] = $customer->getLastname();
            $userData['gender'] = $customer->getGender() ? Gender::MALE : Gender::FEMALE;
            $userData['date_of_birth'] = $customer->getDateOfBirth();

            if ($address) {
                $userData['phone'] = $address->getTelephone();
                $userData['city'] = $address->getCity();
                $userData['state'] = $address->getRegion();
                $userData['zip_code'] = $address->getPostcode();
                $userData['country_code'] = $address->getCountryId();
            }
        }

        return $userData;
    }

    public function prepareContent($content)
    {
        return new Content([
            'product_id' => $content[0],
            'quantity' => $content[1],
            'item_price' => $content[2],
            'title' => $content[3],
        ]);
    }

    public function getCurrentUrl()
    {
        return Mage::helper('core/url')->getCurrentUrl();
    }

    public function getActionSource()
    {
        return ActionSource::WEBSITE;
    }
}
