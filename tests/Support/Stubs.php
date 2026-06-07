<?php

declare(strict_types=1);

namespace HiraleMetaConversions\Tests\Support;

use FacebookAds\Object\ServerSide\Event;
use Throwable;

class QueueStub
{
    /** @var list<array{handler:string,payload:array<string, mixed>,options:array<string, mixed>}> */
    public array $calls = [];

    public ?Throwable $nextException = null;

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     */
    public function enqueue(string $handler, array $payload, array $options = []): string
    {
        $this->calls[] = ['handler' => $handler, 'payload' => $payload, 'options' => $options];
        if ($this->nextException !== null) {
            $e = $this->nextException;
            $this->nextException = null;
            throw $e;
        }

        return 'fake-job-id';
    }
}

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
    /** @var list<array{access_token:string,pixel_id:string,event:Event,debug_mode:bool}> */
    public array $sends = [];

    public mixed $nextResponse = null;

    #[\Override]
    protected function _sendEvent(string $accessToken, string $pixelId, Event $event, bool $debugMode = false): mixed
    {
        $this->sends[] = [
            'access_token' => $accessToken,
            'pixel_id' => $pixelId,
            'event' => $event,
            'debug_mode' => $debugMode,
        ];
        return $this->nextResponse;
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
    ) {}

    public function getId(): ?int
    {
        return $this->id;
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
