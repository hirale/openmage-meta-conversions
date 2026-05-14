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

class GoogleAnalyticsHelperStub
{
    public function getLastCategoryName($product): ?string
    {
        return null;
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
}

class RecordingApi extends \Hirale_MetaConversions_Model_Api
{
    /** @var list<array{access_token:string,pixel_id:string,event:Event}> */
    public array $sends = [];

    public mixed $nextResponse = null;

    #[\Override]
    protected function _sendEvent(string $accessToken, string $pixelId, Event $event): mixed
    {
        $this->sends[] = ['access_token' => $accessToken, 'pixel_id' => $pixelId, 'event' => $event];
        return $this->nextResponse;
    }
}
