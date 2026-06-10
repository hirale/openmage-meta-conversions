<?php

declare(strict_types=1);

namespace HiraleMetaConversions\Tests\Unit;

use HiraleMetaConversions\Tests\Support\AppStub;
use HiraleMetaConversions\Tests\Support\CartItemStub;
use HiraleMetaConversions\Tests\Support\CategoryStub;
use HiraleMetaConversions\Tests\Support\CheckoutSessionStub;
use HiraleMetaConversions\Tests\Support\CookieStub;
use HiraleMetaConversions\Tests\Support\HttpHelperStub;
use HiraleMetaConversions\Tests\Support\LayoutStub;
use HiraleMetaConversions\Tests\Support\ProductStub;
use HiraleMetaConversions\Tests\Support\QuoteItemStub;
use HiraleMetaConversions\Tests\Support\QuoteStub;
use HiraleMetaConversions\Tests\Support\SearchListBlockStub;
use HiraleMetaConversions\Tests\Support\UrlHelperStub;
use HiraleMetaConversions\Tests\Support\WishlistItemStub;
use PHPUnit\Framework\TestCase;

class ObserverTest extends TestCase
{
    protected function setUp(): void
    {
        \Mage::reset();
        \Hirale\Queue\Bus::reset();
        \Mage::$helpers['metaconversions'] = new \Hirale_MetaConversions_Helper_Data();
        \Mage::$helpers['core/http'] = new HttpHelperStub();
        \Mage::$helpers['core/url'] = new UrlHelperStub();
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
        \Hirale\Queue\Bus::reset();
    }

    public function testAddToQueueDispatchesCapiMessageWithStoreContext(): void
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

        self::assertCount(1, \Hirale\Queue\Bus::$dispatches);
        $message = \Hirale\Queue\Bus::$dispatches[0]['message'];
        self::assertInstanceOf(\Hirale_MetaConversions_Message_CapiEventMessage::class, $message);
        self::assertSame(7, $message->storeId);
        self::assertFalse($message->debugMode);
        self::assertSame('AddToCart', $message->event['event_name']);
        self::assertSame('evt-123', $message->event['event_id']);
        self::assertSame(['currency' => 'USD', 'value' => 9.99], $message->customData);
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

        self::assertSame(42, \Hirale\Queue\Bus::$dispatches[0]['message']->storeId);
    }

    public function testAddToQueueSwallowsDispatchExceptions(): void
    {
        \Hirale\Queue\Bus::$nextException = new \RuntimeException('redis down');

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
        $event = $observer->callBuildEvent('AddToCart');

        self::assertSame('AddToCart', $event['event_name']);
        self::assertSame('pixel-side-id', $event['event_id']);
        self::assertSame('https://example.test/test', $event['event_source_url']);
        self::assertArrayHasKey('event_time', $event);
        self::assertArrayHasKey('action_source', $event);
    }

    public function testBuildEventFallsBackToGeneratedEventId(): void
    {
        $observer = new ObserverAccessor();
        $a = $observer->callBuildEvent('Purchase');
        $b = $observer->callBuildEvent('Purchase');

        self::assertNotSame('', $a['event_id']);
        self::assertNotSame($a['event_id'], $b['event_id']);
    }

    public function testInitiateCheckoutValueMultipliesPriceByQuantity(): void
    {
        // summaryQty (9.0) is deliberately NOT 3+2 so the num_items assertion
        // proves the payload uses the quote's own getItemsSummaryQty() rather
        // than coincidentally matching the value loop's quantities.
        $quote = new QuoteStub(
            [
                new QuoteItemStub('SKU-1', 3.0, 10.0, 'Item One'),
                new QuoteItemStub('SKU-2', 2.0, 5.5, 'Item Two'),
            ],
            9.0,
        );
        \Mage::$singletons['checkout/session'] = new CheckoutSessionStub($quote);

        $observer = new ObserverAccessor();
        $data = $observer->callPrepareInitiateCheckoutCustomData('USD');

        // 3 * 10.00 + 2 * 5.50 = 41.00 — not the unit-price-only 15.50.
        self::assertSame(41.0, $data['value']);
        self::assertSame(['SKU-1', 'SKU-2'], $data['content_ids']);
        self::assertSame(9.0, $data['num_items']);
    }

    public function testAddToCartValueReflectsOnlyAddedUnitsNotWholeLine(): void
    {
        \Mage::$helpers['core/http']->userAgent =
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
        $quote = new QuoteStub([], 0.0, 100, 1);
        \Mage::$singletons['checkout/session'] = new CheckoutSessionStub($quote);

        // Existing line raised from qty 2 -> 3: only 1 unit was added.
        $item = new CartItemStub(
            sku: 'SKU-1',
            qty: 3.0,
            basePrice: 10.0,
            name: 'Item',
            id: 5,
            quoteId: 100,
            storeId: 1,
            isNew: false,
            hasChanges: true,
            origQty: 2.0,
        );

        $observer = new \Hirale_MetaConversions_Model_Observer();
        $observer->addToCart(new \Varien_Event_Observer(new \Varien_Event(['item' => $item])));

        self::assertCount(1, \Hirale\Queue\Bus::$dispatches);
        $customData = \Hirale\Queue\Bus::$dispatches[0]['message']->customData;
        // 1 added unit * 10.00 = 10.00, NOT the full base row total (30.00).
        self::assertSame(10.0, $customData['value']);
        self::assertSame(1.0, $customData['contents'][0][1]);
    }

    public function testAddToWishlistValueAccountsForItemQuantity(): void
    {
        \Mage::$helpers['core/http']->userAgent =
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
        // store_id 7 differs from the current store (1) so the assertion below
        // proves the per-item store id is honored. The stub uses magic getters,
        // so a method_exists()-guarded regression would fall back (qty 1 /
        // current store) and fail this test.
        $item = new WishlistItemStub([
            'product' => new ProductStub('SKU-1', 10.0, 'Item'),
            'qty' => 2.0,
            'store_id' => 7,
        ]);

        $observer = new \Hirale_MetaConversions_Model_Observer();
        $observer->addToWishlist(new \Varien_Event_Observer(new \Varien_Event(['items' => [$item]])));

        self::assertCount(1, \Hirale\Queue\Bus::$dispatches);
        $message = \Hirale\Queue\Bus::$dispatches[0]['message'];
        $customData = $message->customData;
        // 10.00 * 2 = 20.00 — the item quantity is no longer hardcoded to 1.
        self::assertSame(20.0, $customData['value']);
        self::assertSame(2.0, $customData['contents'][0][1]);
        // Per-item store id is resolved (was dead code under method_exists).
        self::assertSame(7, $message->storeId);
    }

    public function testBuildContentRowCastsNullSkuAndNameToString(): void
    {
        $observer = new ObserverAccessor();
        // Null sku/name (deleted-product edge) must not raise a TypeError.
        $row = $observer->callBuildContentRow(null, 1, 9.999, null);

        self::assertSame('', $row[0]);
        self::assertSame('', $row[3]);
        self::assertSame(10.0, $row[2]); // formatPrice rounds 9.999 -> 10.00
    }

    public function testPrepareSearchBuildsContentsFromLoadedCollection(): void
    {
        $app = new AppStub(1);
        $app->layout = new LayoutStub([
            'search_result_list' => new SearchListBlockStub([
                new ProductStub('SKU-1', 9.99, 'One'),
                new ProductStub('SKU-2', 5.0, 'Two'),
            ]),
        ]);
        \Mage::$app = $app;

        $observer = new ObserverAccessor();
        $data = $observer->callPrepareSearchCustomData('USD', 'shoes');

        self::assertSame(['SKU-1', 'SKU-2'], $data['content_ids']);
        self::assertSame('shoes', $data['search_string']);
        self::assertCount(2, $data['contents']);
        self::assertSame(9.99, $data['contents'][0][2]);
    }

    public function testPrepareSearchReturnsEmptyContentsWhenBlockMissing(): void
    {
        $app = new AppStub(1);
        $app->layout = new LayoutStub([]); // no 'search_result_list' block
        \Mage::$app = $app;

        $observer = new ObserverAccessor();
        $data = $observer->callPrepareSearchCustomData('USD', 'shoes');

        self::assertSame([], $data['content_ids']);
        self::assertSame([], $data['contents']);
        self::assertSame('shoes', $data['search_string']);
    }

    public function testViewContentResolvesCategoryWithoutGoogleAnalytics(): void
    {
        // content_category is resolved natively from the current_category
        // registry. setUp registers no 'googleanalytics' helper, so any
        // residual GA call anywhere in the observer's constructor/path would
        // make Mage::helper() throw — the whole observer suite passing is the
        // structural evidence for the decouple.
        \Mage::register('current_product', new ProductStub('SKU-9', 12.5, 'Viewed Product'));
        \Mage::register('current_category', new CategoryStub('Shoes'));

        $observer = new ObserverAccessor();
        $data = $observer->callPrepareViewContentCustomData('USD');

        self::assertSame('Shoes', $data['content_category']);
        self::assertSame(['SKU-9'], $data['content_ids']);
        self::assertSame('product', $data['content_type']);
    }

    public function testViewContentCategoryIsEmptyWhenNoCurrentCategory(): void
    {
        \Mage::register('current_product', new ProductStub('SKU-9', 12.5, 'Viewed Product'));

        $observer = new ObserverAccessor();
        $data = $observer->callPrepareViewContentCustomData('USD');

        self::assertSame('', $data['content_category']);
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
    public function callBuildEvent(string $eventName): array
    {
        return $this->buildEvent($eventName);
    }

    /**
     * @return array<string, mixed>
     */
    public function callPrepareInitiateCheckoutCustomData(string $currency): array
    {
        return $this->prepareInitiateCheckoutCustomData($currency);
    }

    /**
     * @return array<string, mixed>
     */
    public function callPrepareViewContentCustomData(string $currency): array
    {
        return $this->prepareViewContentCustomData($currency);
    }

    /**
     * @return array<string, mixed>
     */
    public function callPrepareSearchCustomData(string $currency, string $q): array
    {
        return $this->prepareSearchCustomData($currency, $q);
    }

    /**
     * @param string|null $sku
     * @param int|float|string $qty
     * @param int|float|string $price
     * @param string|null $name
     * @return array{0:string,1:int|float|string,2:float,3:string}
     */
    public function callBuildContentRow($sku, $qty, $price, $name): array
    {
        return $this->buildContentRow($sku, $qty, $price, $name);
    }
}
