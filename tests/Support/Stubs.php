<?php

declare(strict_types=1);

namespace HiraleMetaConversions\Tests\Support;

use FacebookAds\Object\ServerSide\Event;
use Throwable;

class HttpHelperStub
{
    public string $remoteAddr = '127.0.0.1';
    public string $userAgent = 'Mozilla/5.0 (test)';

    public function getRemoteAddr(): string
    {
        return $this->remoteAddr;
    }

    public function getHttpUserAgent(): string
    {
        return $this->userAgent;
    }
}

class UrlHelperStub
{
    public string $currentUrl = 'https://example.test/test';

    public function getCurrentUrl(): string
    {
        return $this->currentUrl;
    }
}

class CookieStub
{
    /** @var array<string, string> */
    public array $values = [];

    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }
}

class StoreStub
{
    public int $id;
    public string $baseCurrencyCode;

    public function __construct(int $id = 1, string $currency = 'USD')
    {
        $this->id = $id;
        $this->baseCurrencyCode = $currency;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getBaseCurrencyCode(): string
    {
        return $this->baseCurrencyCode;
    }
}

class AppStub
{
    /** @var array<int, StoreStub> */
    public array $stores = [];
    public StoreStub $currentStore;
    public ?object $layout = null;

    public function __construct(int $currentStoreId = 1)
    {
        $this->currentStore = new StoreStub($currentStoreId);
        $this->stores[$currentStoreId] = $this->currentStore;
    }

    public function getStore(?int $storeId = null): StoreStub
    {
        if ($storeId === null || !isset($this->stores[$storeId])) {
            return $this->currentStore;
        }
        return $this->stores[$storeId];
    }

    public function getLayout(): object
    {
        if ($this->layout === null) {
            $this->layout = new LayoutStub();
        }
        return $this->layout;
    }
}

class RecordingApi extends \Hirale_MetaConversions_Model_Api
{
    /** @var list<array{access_token:string,pixel_id:string,events:list<Event>,debug_mode:bool}> */
    public array $sends = [];

    public mixed $nextResponse = null;

    public ?Throwable $nextThrowable = null;

    /** @param list<Event> $events */
    #[\Override]
    protected function _sendEvents(string $accessToken, string $pixelId, array $events, bool $debugMode = false): mixed
    {
        if ($this->nextThrowable !== null) {
            $e = $this->nextThrowable;
            $this->nextThrowable = null;
            throw $e;
        }
        $this->sends[] = [
            'access_token' => $accessToken,
            'pixel_id' => $pixelId,
            'events' => $events,
            'debug_mode' => $debugMode,
        ];
        return $this->nextResponse;
    }
}

class CoreHelperStub extends \Mage_Core_Helper_Abstract
{
    /** @var list<string> */
    public array $decryptCalls = [];

    /**
     * Mimics Mage_Core_Helper_Data::decrypt for the unit suite: values
     * prefixed with "enc:" decrypt to the rest of the string, anything else
     * passes through unchanged (covers tests that store plain values).
     */
    public function decrypt(string $value): string
    {
        $this->decryptCalls[] = $value;
        return str_starts_with($value, 'enc:') ? substr($value, 4) : $value;
    }
}

class AddressStub
{
    public function __construct(
        private string $telephone = '',
        private string $city = '',
        private string $region = '',
        private string $postcode = '',
        private string $countryId = '',
    ) {}

    public function getTelephone(): string
    {
        return $this->telephone;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function getRegion(): string
    {
        return $this->region;
    }

    public function getPostcode(): string
    {
        return $this->postcode;
    }

    public function getCountryId(): string
    {
        return $this->countryId;
    }
}

class RequestStub
{
    /** @param array<string, mixed> $params */
    public function __construct(
        private string $moduleName,
        private string $controllerName,
        private string $actionName,
        private array $params = [],
    ) {}

    public function getModuleName(): string
    {
        return $this->moduleName;
    }

    public function getControllerName(): string
    {
        return $this->controllerName;
    }

    public function getActionName(): string
    {
        return $this->actionName;
    }

    public function getParam(string $key): mixed
    {
        return $this->params[$key] ?? null;
    }
}

class ResponseStub
{
    /** @param list<string> $bodySegments */
    public function __construct(
        private int $statusCode = 200,
        private array $bodySegments = [],
    ) {}

    public function getHttpResponseCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Mirrors Zend_Controller_Response_Abstract::getBody(): true returns the
     * raw segment array, false the concatenated string.
     */
    public function getBody(bool $spec = false): array|string
    {
        return $spec ? $this->bodySegments : implode('', $this->bodySegments);
    }
}

class RouteAppStub
{
    public function __construct(
        private RequestStub $request,
        private ResponseStub $response,
    ) {}

    public function getRequest(): RequestStub
    {
        return $this->request;
    }

    public function getResponse(): ResponseStub
    {
        return $this->response;
    }
}

class ProductStub
{
    public function __construct(
        private string $sku,
        private float $finalPrice,
        private string $name,
    ) {}

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getFinalPrice(): float
    {
        return $this->finalPrice;
    }

    public function getName(): string
    {
        return $this->name;
    }
}

class CategoryStub
{
    public function __construct(private string $name) {}

    public function getName(): string
    {
        return $this->name;
    }
}

class QuoteItemStub
{
    public function __construct(
        private string $sku,
        private float $qty,
        private float $basePrice,
        private string $name,
        private ?object $parentItem = null,
    ) {}

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getQty(): float
    {
        return $this->qty;
    }

    public function getBasePrice(): float
    {
        return $this->basePrice;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getParentItem(): ?object
    {
        return $this->parentItem;
    }
}

class QuoteStub
{
    /**
     * @param list<QuoteItemStub> $items
     */
    public function __construct(
        private array $items = [],
        private float $summaryQty = 0.0,
        private int $id = 1,
        private int $storeId = 1,
    ) {}

    /**
     * @return list<QuoteItemStub>
     */
    public function getAllVisibleItems(): array
    {
        return $this->items;
    }

    public function getItemsSummaryQty(): float
    {
        return $this->summaryQty;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getStoreId(): int
    {
        return $this->storeId;
    }
}

class CheckoutSessionStub
{
    public function __construct(private ?object $quote = null) {}

    public function getQuote(): ?object
    {
        return $this->quote;
    }
}

class CartItemStub
{
    public function __construct(
        private string $sku,
        private float $qty,
        private float $basePrice,
        private string $name,
        private int $id = 1,
        private int $quoteId = 1,
        private int $storeId = 1,
        private bool $isNew = true,
        private bool $hasChanges = false,
        private ?float $origQty = null,
        private ?object $parentItem = null,
    ) {}

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getQty(): float
    {
        return $this->qty;
    }

    public function getBasePrice(): float
    {
        return $this->basePrice;
    }

    public function getBaseRowTotal(): float
    {
        return $this->basePrice * $this->qty;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getQuoteId(): int
    {
        return $this->quoteId;
    }

    public function getStoreId(): int
    {
        return $this->storeId;
    }

    public function isObjectNew(): bool
    {
        return $this->isNew;
    }

    public function hasDataChanges(): bool
    {
        return $this->hasChanges;
    }

    public function getParentItem(): ?object
    {
        return $this->parentItem;
    }

    public function getOrigData(?string $key = null): mixed
    {
        return $key === 'qty' ? $this->origQty : null;
    }
}

/**
 * Mirrors Mage_Wishlist_Model_Item, where getQty()/getStoreId() are Varien
 * MAGIC getters — so method_exists($item, 'getQty') is false on the real model.
 * The stub uses __call for the same reason: an explicit-method stub would make
 * method_exists()-guarded observer code pass here while failing in production.
 *
 * @method object|null getProduct()
 * @method mixed getQty()
 * @method mixed getStoreId()
 */
class WishlistItemStub
{
    /** @param array<string, mixed> $data */
    public function __construct(private array $data = []) {}

    public function __call(string $name, array $args): mixed
    {
        if (str_starts_with($name, 'get')) {
            $key = strtolower((string) preg_replace('/(.)([A-Z])/', '$1_$2', substr($name, 3)));
            return $this->data[$key] ?? null;
        }
        return null;
    }
}

class SearchListBlockStub
{
    /**
     * @param iterable<object> $products
     */
    public function __construct(private iterable $products = []) {}

    /**
     * @return iterable<object>
     */
    public function getLoadedProductCollection(): iterable
    {
        return $this->products;
    }
}

class LayoutStub
{
    /**
     * @param array<string, object> $blocks
     */
    public function __construct(private array $blocks = []) {}

    public function getBlock(string $name): object|false
    {
        return $this->blocks[$name] ?? false;
    }
}

class CustomerStub
{
    /**
     * @param int|string|null $gender
     */
    public function __construct(
        private ?int $id = null,
        private string $email = '',
        private string $firstname = '',
        private string $lastname = '',
        private $gender = null,
        private string $dateOfBirth = '',
        private ?object $billingAddress = null,
        private ?int $storeId = null,
    ) {}

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStoreId(): ?int
    {
        return $this->storeId;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getFirstname(): string
    {
        return $this->firstname;
    }

    public function getLastname(): string
    {
        return $this->lastname;
    }

    /**
     * @return int|string|null
     */
    public function getGender()
    {
        return $this->gender;
    }

    public function getDateOfBirth(): string
    {
        return $this->dateOfBirth;
    }

    public function getDefaultBillingAddress(): ?object
    {
        return $this->billingAddress;
    }
}
