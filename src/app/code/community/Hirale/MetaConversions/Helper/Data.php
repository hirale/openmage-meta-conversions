<?php

declare(strict_types=1);

use FacebookAds\Object\ServerSide\ActionSource;
use FacebookAds\Object\ServerSide\Content;
use FacebookAds\Object\ServerSide\Gender;

class Hirale_MetaConversions_Helper_Data extends Mage_Core_Helper_Abstract
{
    public const REGISTRY_EVENT_ID_PREFIX = 'hirale_meta_event_id_';

    private const CACHE_KEY_NULL = '__current__';

    /** @var array<string, bool> */
    private array $_isConversionsEnabled = [];

    /** @var array<string, bool> */
    private array $_isDebugMode = [];

    /** @var array<string, string|null> */
    private array $_accessToken = [];

    /** @var array<string, string|null> */
    private array $_pixelId = [];

    private ?object $_cookie = null;

    public function isConversionsEnabled(?int $storeId = null): bool
    {
        $key = $this->_cacheKey($storeId);
        if (!array_key_exists($key, $this->_isConversionsEnabled)) {
            $this->_isConversionsEnabled[$key] = (bool) Mage::getStoreConfig('meta/conversions/enabled', $storeId);
        }

        return $this->_isConversionsEnabled[$key];
    }

    public function isDebugMode(?int $storeId = null): bool
    {
        $key = $this->_cacheKey($storeId);
        if (!array_key_exists($key, $this->_isDebugMode)) {
            $this->_isDebugMode[$key] = (bool) Mage::getStoreConfig('meta/conversions/debug_mode', $storeId);
        }

        return $this->_isDebugMode[$key];
    }

    public function getAccessToken(?int $storeId = null): ?string
    {
        $key = $this->_cacheKey($storeId);
        if (!array_key_exists($key, $this->_accessToken)) {
            $value = Mage::getStoreConfig('meta/conversions/access_token', $storeId);
            $this->_accessToken[$key] = is_string($value) && $value !== '' ? $value : null;
        }

        return $this->_accessToken[$key];
    }

    public function getPixelId(?int $storeId = null): ?string
    {
        $key = $this->_cacheKey($storeId);
        if (!array_key_exists($key, $this->_pixelId)) {
            $value = Mage::getStoreConfig('meta/conversions/pixel_id', $storeId);
            $this->_pixelId[$key] = is_string($value) && $value !== '' ? $value : null;
        }

        return $this->_pixelId[$key];
    }

    /**
     * Resolve the event_id to attach to the outgoing CAPI event.
     *
     * For Meta's Pixel↔CAPI deduplication the SAME event_id must be sent
     * from both browser (fbq + { eventID }) and server. When the storefront
     * Pixel template registers the id under
     * `hirale_meta_event_id_<EventName>` before the observer fires, this
     * helper returns it; otherwise it falls back to a server-generated
     * uniqid, which is still useful for queue-side log dedup even though
     * Meta cannot dedupe it against a different browser-side id.
     */
    public function getEventId(?string $eventName = null): string
    {
        if ($eventName !== null && $eventName !== '') {
            $registered = Mage::registry(self::REGISTRY_EVENT_ID_PREFIX . $eventName);
            if (is_string($registered) && $registered !== '') {
                return $registered;
            }
        }

        return uniqid('', true);
    }

    /**
     * @param int|float|string $price
     */
    public function formatPrice($price): float
    {
        return (float) number_format((float) $price, 2, '.', '');
    }

    /**
     * @return array<string, mixed>
     */
    public function prepareUserData($customer = null): array
    {
        $cookie = $this->_getCookie();
        $userData = [
            'client_ip_address' => (string) Mage::helper('core/http')->getRemoteAddr(),
            'client_user_agent' => (string) Mage::helper('core/http')->getHttpUserAgent(),
            'fbp' => (string) ($cookie->get('_fbp') ?? ''),
            'fbc' => (string) ($cookie->get('_fbc') ?? ''),
        ];

        if (!$customer && Mage::getSingleton('customer/session')->isLoggedIn()) {
            $customer = Mage::getSingleton('customer/session')->getCustomer();
        }
        if ($customer) {
            $address = method_exists($customer, 'getDefaultBillingAddress') ? $customer->getDefaultBillingAddress() : null;
            $userData['email'] = (string) $customer->getEmail();
            $userData['first_name'] = (string) $customer->getFirstname();
            $userData['last_name'] = (string) $customer->getLastname();

            if ($customer->getId()) {
                $userData['external_id'] = (string) $customer->getId();
            }

            $gender = $this->_mapGender($customer->getGender());
            if ($gender !== null) {
                $userData['gender'] = $gender;
            }

            $dateOfBirth = $this->_formatDateOfBirth($customer->getDateOfBirth());
            if ($dateOfBirth !== null) {
                $userData['date_of_birth'] = $dateOfBirth;
            }

            if ($address) {
                $userData['phone'] = (string) $address->getTelephone();
                $userData['city'] = (string) $address->getCity();
                $userData['state'] = (string) $address->getRegion();
                $userData['zip_code'] = (string) $address->getPostcode();
                $userData['country_code'] = (string) $address->getCountryId();
            }
        }

        return $userData;
    }

    /**
     * Map Magento's numeric gender (1 = male, 2 = female) to Meta's gender
     * code. Returns null for "not specified" so the key is omitted rather
     * than guessed — a wrong value hurts match quality.
     *
     * @param int|string|null $gender
     */
    private function _mapGender($gender): ?string
    {
        return match ((int) $gender) {
            1 => Gender::MALE,
            2 => Gender::FEMALE,
            default => null,
        };
    }

    /**
     * Normalize a stored date of birth to Meta's expected YYYYMMDD form.
     * Returns null when the value is empty, malformed, or implausible.
     *
     * Magento stores the DOB as `Y-m-d` (optionally with a time component), so
     * the date part is parsed strictly. strtotime() is deliberately avoided:
     * it coerces the MySQL zero date '0000-00-00' into a far-past timestamp
     * (yielding a bogus '-00011130') and silently misreads ambiguous formats
     * like '06/05/1990' instead of rejecting them.
     *
     * @param string|null $dateOfBirth
     */
    private function _formatDateOfBirth($dateOfBirth): ?string
    {
        $dateOfBirth = trim((string) $dateOfBirth);
        if ($dateOfBirth === '') {
            return null;
        }

        $date = DateTime::createFromFormat('!Y-m-d', substr($dateOfBirth, 0, 10));
        $errors = DateTime::getLastErrors();
        if ($date === false || ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        $year = (int) $date->format('Y');
        if ($year < 1900 || $year > (int) date('Y')) {
            return null;
        }

        return $date->format('Ymd');
    }

    /**
     * @param array{0:string,1:int|float,2:int|float,3:string} $content
     */
    public function prepareContent(array $content): Content
    {
        return new Content([
            'product_id' => $content[0],
            'quantity' => $content[1],
            'item_price' => $content[2],
            'title' => $content[3],
        ]);
    }

    public function getCurrentUrl(): string
    {
        return (string) Mage::helper('core/url')->getCurrentUrl();
    }

    public function getActionSource(): string
    {
        return ActionSource::WEBSITE;
    }

    private function _getCookie(): object
    {
        if ($this->_cookie === null) {
            $this->_cookie = Mage::getSingleton('core/cookie');
        }

        return $this->_cookie;
    }

    private function _cacheKey(?int $storeId): string
    {
        return $storeId === null ? self::CACHE_KEY_NULL : (string) $storeId;
    }
}
