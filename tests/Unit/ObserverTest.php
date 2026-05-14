<?php

declare(strict_types=1);

namespace HiraleMetaConversions\Tests\Unit;

use HiraleMetaConversions\Tests\Support\AppStub;
use HiraleMetaConversions\Tests\Support\CookieStub;
use HiraleMetaConversions\Tests\Support\GoogleAnalyticsHelperStub;
use HiraleMetaConversions\Tests\Support\HttpHelperStub;
use HiraleMetaConversions\Tests\Support\QueueStub;
use HiraleMetaConversions\Tests\Support\UrlHelperStub;
use PHPUnit\Framework\TestCase;

class ObserverTest extends TestCase
{
    private QueueStub $queue;

    protected function setUp(): void
    {
        \Mage::reset();
        $this->queue = new QueueStub();
        \Mage::$helpers['metaconversions'] = new \Hirale_MetaConversions_Helper_Data();
        \Mage::$helpers['googleanalytics'] = new GoogleAnalyticsHelperStub();
        \Mage::$helpers['core/http'] = new HttpHelperStub();
        \Mage::$helpers['core/url'] = new UrlHelperStub();
        \Mage::$models['hirale_queue/queue'] = $this->queue;
        \Mage::$singletons['core/cookie'] = new CookieStub();
        \Mage::$singletons['customer/session'] = new \Mage_Customer_Model_Session();
        \Mage::$app = new AppStub(1);
        \Mage::$config = ['__null__' => [], '1' => [], '7' => []];
        \Mage::$config['1']['meta/conversions/enabled'] = '1';
        \Mage::$config['1']['meta/conversions/access_token'] = 'token-1';
        \Mage::$config['1']['meta/conversions/pixel_id'] = '111';
        \Mage::$config['7']['meta/conversions/enabled'] = '1';
        \Mage::$config['7']['meta/conversions/access_token'] = 'token-7';
        \Mage::$config['7']['meta/conversions/pixel_id'] = '707';
    }

    protected function tearDown(): void
    {
        \Mage::reset();
    }

    public function testAddToQueueIncludesStoreIdAndDebugFlagAndMetadata(): void
    {
        $observer = new ObserverAccessor();
        $observer->callAddToQueue(
            [
                'event_name' => 'AddToCart',
                'event_id' => 'evt-123',
                'event_time' => 1700000000,
                'event_source_url' => 'https://example.test/cart',
                'action_source' => 'website',
            ],
            ['client_ip_address' => '1.2.3.4'],
            ['currency' => 'USD', 'value' => 9.99],
            7,
        );

        self::assertCount(1, $this->queue->calls);
        $call = $this->queue->calls[0];
        self::assertSame('Hirale_MetaConversions_Model_Api', $call['handler']);
        self::assertSame(7, $call['payload']['_store_id']);
        self::assertFalse($call['payload']['_debug_mode']);
        self::assertSame('AddToCart', $call['payload']['event']['event_name']);
        self::assertSame('evt-123', $call['payload']['event']['event_id']);
        self::assertSame('hirale_metaconversions', $call['options']['metadata']['source']);
        self::assertSame(7, $call['options']['metadata']['store_id']);
        self::assertSame('AddToCart', $call['options']['metadata']['event_name']);
        self::assertSame('evt-123', $call['options']['metadata']['event_id']);
    }

    public function testAddToQueueFallsBackToCurrentStoreWhenStoreIdMissing(): void
    {
        \Mage::$app = new AppStub(42);
        \Mage::$config['42'] = ['meta/conversions/enabled' => '1'];

        $observer = new ObserverAccessor();
        $observer->callAddToQueue(
            ['event_name' => 'PageView', 'event_id' => 'evt-x'],
            [],
            null,
            null,
        );

        self::assertSame(42, $this->queue->calls[0]['payload']['_store_id']);
    }

    public function testAddToQueueSwallowsQueueExceptions(): void
    {
        $this->queue->nextException = new \RuntimeException('redis down');

        $observer = new ObserverAccessor();
        $observer->callAddToQueue(
            ['event_name' => 'AddToCart', 'event_id' => 'evt-1'],
            [],
            null,
            1,
        );

        self::assertCount(1, \Mage::$exceptions);
        self::assertSame('redis down', \Mage::$exceptions[0]->getMessage());
    }

    public function testBuildEventReadsRegistryEventIdForPixelDedup(): void
    {
        \Mage::register('hirale_meta_event_id_AddToCart', 'pixel-side-id');

        $observer = new ObserverAccessor();
        $event = $observer->callBuildEvent('AddToCart', 1);

        self::assertSame('AddToCart', $event['event_name']);
        self::assertSame('pixel-side-id', $event['event_id']);
        self::assertSame('https://example.test/test', $event['event_source_url']);
        self::assertArrayHasKey('event_time', $event);
        self::assertArrayHasKey('action_source', $event);
    }

    public function testBuildEventFallsBackToGeneratedEventId(): void
    {
        $observer = new ObserverAccessor();
        $a = $observer->callBuildEvent('Purchase', 1);
        $b = $observer->callBuildEvent('Purchase', 1);

        self::assertNotSame('', $a['event_id']);
        self::assertNotSame($a['event_id'], $b['event_id']);
    }
}

class ObserverAccessor extends \Hirale_MetaConversions_Model_Observer
{
    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $userData
     * @param array<string, mixed>|null $customData
     */
    public function callAddToQueue(array $event, array $userData, ?array $customData, ?int $storeId): void
    {
        $this->addToQueue($event, $userData, $customData, $storeId);
    }

    /**
     * @return array<string, mixed>
     */
    public function callBuildEvent(string $eventName, ?int $storeId): array
    {
        return $this->buildEvent($eventName, $storeId);
    }
}
