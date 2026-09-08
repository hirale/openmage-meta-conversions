<?php

declare(strict_types=1);

use FacebookAds\Object\ServerSide\ActionSource;
use FacebookAds\Object\ServerSide\Content;
use FacebookAds\Object\ServerSide\Gender;
use FacebookAds\Object\ServerSide\Normalizer;
use FacebookAds\Object\ServerSide\Util;
use Hirale\Queue\Bus;
use Maho\Queue\QueueManager;

class Hirale_MetaConversions_Helper_Data extends Mage_Core_Helper_Abstract
{
    public const REGISTRY_EVENT_ID_PREFIX = 'hirale_meta_event_id_';

    /** Queue CAPI uploads ride; config.xml routes it off the resident fast pool on Maho. */
    public const QUEUE_ANALYTICS = 'analytics';

    private const DISPATCHER_MAHO = 'maho';
    private const DISPATCHER_HIRALE = 'hirale';
    private const DISPATCHER_NONE = 'none';

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

    private ?string $_dispatcher = null;

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
            // The admin field uses adminhtml/system_config_backend_encrypted,
            // so the stored value must be decrypted before use.
            if (is_string($value) && $value !== '') {
                $value = (string) Mage::helper('core')->decrypt($value);
            }
            $this->_accessToken[$key] = is_string($value) && trim($value) !== '' ? trim($value) : null;
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
     * Mint the event_id for an event of this request and leave it where the
     * observer will find it.
     *
     * This is the storefront side of Meta's Pixel↔CAPI deduplication: the same
     * event_id has to reach Meta twice, once from the browser as
     * `fbq('track', '<EventName>', {...}, { eventID: '<id>' })` and once from
     * the server. Call this in the template that emits the Pixel call, use the
     * returned id there, and the observer will attach the same one to the
     * queued CAPI event. Calling it twice for the same event name in one
     * request returns the id already reserved.
     *
     * Without a reservation nothing breaks — the observer generates its own id
     * — but Meta then sees the browser and server events as two distinct
     * events and counts both.
     */
    public function reserveEventId(string $eventName): string
    {
        $key = self::REGISTRY_EVENT_ID_PREFIX . $eventName;
        $reserved = Mage::registry($key);
        if (is_string($reserved) && $reserved !== '') {
            return $reserved;
        }

        $eventId = uniqid('', true);
        Mage::register($key, $eventId, true);

        return $eventId;
    }

    /**
     * Read side of the same mechanism, used by the observer: return the id
     * reserveEventId() left in the registry for this event name, or a fresh
     * one when the storefront reserved none. A generated id is still useful
     * for queue-side log dedup, but Meta cannot match it against a different
     * browser-side id — see reserveEventId().
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
     * Build the CAPI user_data payload. PII fields are normalized and SHA-256
     * hashed here — at capture time — so the queue backend never stores
     * cleartext customer data; client_ip_address / client_user_agent / fbp /
     * fbc stay raw because Meta requires them unhashed.
     *
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
            $this->_addHashedField($userData, 'email', 'em', $customer->getEmail());
            $this->_addHashedField($userData, 'first_name', 'fn', $customer->getFirstname());
            $this->_addHashedField($userData, 'last_name', 'ln', $customer->getLastname());

            if ($customer->getId()) {
                // Kept raw (the SDK only dedups external_id): the browser-side
                // Pixel sends the raw customer id, and hashing one side only
                // would break the Pixel↔CAPI identity match.
                $userData['external_id'] = (string) $customer->getId();
            }

            $gender = $this->_mapGender($customer->getGender());
            if ($gender !== null) {
                $this->_addHashedField($userData, 'gender', 'ge', $gender);
            }

            $dateOfBirth = $this->_formatDateOfBirth($customer->getDateOfBirth());
            if ($dateOfBirth !== null) {
                $this->_addHashedField($userData, 'date_of_birth', 'db', $dateOfBirth);
            }

            if ($address) {
                $this->_addHashedField($userData, 'phone', 'ph', $address->getTelephone());
                $this->_addHashedField($userData, 'city', 'ct', $address->getCity());
                $this->_addHashedField($userData, 'state', 'st', $address->getRegion());
                $this->_addHashedField($userData, 'zip_code', 'zp', $address->getPostcode());
                $this->_addHashedField($userData, 'country_code', 'country', $address->getCountryId());
            }
        }

        return $userData;
    }

    /**
     * Normalize + SHA-256 one PII value with the SDK's own routines and add
     * it under $key. The worker-side UserData::normalize() detects the 64-hex
     * digest (Util::isHashed) and passes it through unchanged, so the payload
     * Meta receives is byte-identical to worker-side hashing.
     *
     * Empty values are omitted entirely (hashing '' would send a junk-match
     * digest Meta cannot use), and values the SDK normalizer rejects (e.g. a
     * malformed email) are dropped rather than allowed to break the
     * storefront request.
     *
     * @param array<string, mixed> $userData
     * @param string $key UserData constructor key (email, phone, ...)
     * @param string $sdkField SDK Normalizer field code (em, ph, ...)
     * @param mixed $value
     */
    private function _addHashedField(array &$userData, string $key, string $sdkField, $value): void
    {
        $value = trim((string) $value);
        if ($value === '') {
            return;
        }

        try {
            $normalized = Normalizer::normalize($sdkField, $value);
        } catch (Exception $e) {
            return;
        }
        if ($normalized === null || $normalized === '') {
            return;
        }

        $userData[$key] = (string) Util::hash($normalized);
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

    public function isQueueEnabled(): bool
    {
        return $this->_resolveDispatcher() !== self::DISPATCHER_NONE;
    }

    /**
     * Hand one request's worth of CAPI events to whichever queue backend this
     * install has. Returns false when there is none, so an observer on the
     * request path stays silent instead of failing the page it is measuring.
     *
     * @param list<array{event: array<string, mixed>, custom_data: array<string, mixed>|null}> $events
     * @param array<string, mixed> $userData
     */
    public function enqueueCapiEvents(array $events, array $userData, int $storeId, bool $debugMode): bool
    {
        $dispatcher = $this->_resolveDispatcher();
        if ($dispatcher === self::DISPATCHER_NONE) {
            return false;
        }

        try {
            $this->_dispatch($dispatcher, new Hirale_MetaConversions_Message_CapiEventMessage(
                events: $events,
                userData: $userData,
                storeId: $storeId,
                debugMode: $debugMode,
            ));

            return true;
        } catch (Throwable $e) {
            Mage::logException($e);

            return false;
        }
    }

    /**
     * Which queue backend this install dispatches through. Maho's core queue
     * wins when the platform ships it, so a Maho store needs no third-party
     * queue package at all; hirale/queue remains the OpenMage backend.
     */
    private function _resolveDispatcher(): string
    {
        // Memoized: a page view can dispatch several event batches, and the
        // helper is a per-request singleton.
        if ($this->_dispatcher === null) {
            $this->_dispatcher = match (true) {
                $this->_isMahoQueueAvailable() => self::DISPATCHER_MAHO,
                $this->_isHiraleQueueAvailable() => self::DISPATCHER_HIRALE,
                default => self::DISPATCHER_NONE,
            };
        }

        return $this->_dispatcher;
    }

    private function _isMahoQueueAvailable(): bool
    {
        if (!class_exists(QueueManager::class)) {
            return false;
        }

        $core = Mage::helper('core');

        return $core instanceof Mage_Core_Helper_Abstract && $core->isModuleEnabled('Maho_Queue');
    }

    /** Protected only so the unit suite can simulate an install with no queue package at all. */
    protected function _isHiraleQueueAvailable(): bool
    {
        return class_exists(Bus::class);
    }

    private function _dispatch(string $dispatcher, object $message): void
    {
        if ($dispatcher === self::DISPATCHER_MAHO) {
            QueueManager::dispatch(message: $message, queue: self::QUEUE_ANALYTICS);

            return;
        }

        // hirale/queue takes the queue from its own <routing> in config.xml,
        // which already puts this message class on the analytics queue.
        Bus::dispatch($message);
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
