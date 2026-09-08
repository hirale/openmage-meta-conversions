<?php

declare(strict_types=1);

namespace HiraleMetaConversions\Tests\Unit;

use HiraleMetaConversions\Tests\Support\AppStub;
use HiraleMetaConversions\Tests\Support\CartItemStub;
use HiraleMetaConversions\Tests\Support\CategoryStub;
use HiraleMetaConversions\Tests\Support\CheckoutSessionStub;
use HiraleMetaConversions\Tests\Support\CookieStub;
use HiraleMetaConversions\Tests\Support\CoreHelperStub;
use HiraleMetaConversions\Tests\Support\CustomerStub;
use HiraleMetaConversions\Tests\Support\OrderStub;
use HiraleMetaConversions\Tests\Support\ThrowingHelperStub;
use HiraleMetaConversions\Tests\Support\HttpHelperStub;
use HiraleMetaConversions\Tests\Support\LayoutStub;
use HiraleMetaConversions\Tests\Support\ProductStub;
use HiraleMetaConversions\Tests\Support\QuoteItemStub;
use HiraleMetaConversions\Tests\Support\QuoteStub;
use HiraleMetaConversions\Tests\Support\RequestStub;
use HiraleMetaConversions\Tests\Support\ResponseStub;
use HiraleMetaConversions\Tests\Support\RouteAppStub;
use HiraleMetaConversions\Tests\Support\SearchListBlockStub;
use HiraleMetaConversions\Tests\Support\UrlHelperStub;
use HiraleMetaConversions\Tests\Support\WishlistItemStub;
use PHPUnit\Framework\TestCase;

class ObserverTest extends TestCase
{
    private const BROWSER_UA =
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

    protected function setUp(): void
    {
        \Mage::reset();
        \Hirale\Queue\Bus::reset();
        \Mage::$helpers['metaconversions'] = new \Hirale_MetaConversions_Helper_Data();
        // The queue bridge probes Mage::helper('core')->isModuleEnabled().
        \Mage::$helpers['core'] = new CoreHelperStub();
        \Mage::$helpers['core/http'] = new HttpHelperStub();
        // A real browser UA by default so CrawlerDetect lets events through;
        // the bot test overrides it.
        \Mage::$helpers['core/http']->userAgent = self::BROWSER_UA;
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

    private function routeObserver(
        string $module,
        string $controller,
        string $action,
        ResponseStub $response,
        array $params = [],
    ): \Varien_Event_Observer {
        $app = new RouteAppStub(new RequestStub($module, $controller, $action, $params), $response);

        return new \Varien_Event_Observer(new \Varien_Event(['app' => $app]));
    }

    public function testAddToQueueDispatchesCapiMessageWithStoreContext(): void
    {
        $observer = new ObserverAccessor();
        $observer->callAddToQueue(
            [
                [
                    'event' => [
                        'event_name' => 'AddToCart',
                        'event_id' => 'evt-123',
                        'event_time' => 1700000000,
                        'event_source_url' => 'https://example.test/cart',
                        'action_source' => 'website',
                    ],
                    'custom_data' => ['currency' => 'USD', 'value' => 9.99],
                ],
            ],
            ['client_ip_address' => '1.2.3.4'],
            7,
        );

        self::assertCount(1, \Hirale\Queue\Bus::$dispatches);
        $message = \Hirale\Queue\Bus::$dispatches[0]['message'];
        self::assertInstanceOf(\Hirale_MetaConversions_Message_CapiEventMessage::class, $message);
        self::assertSame(7, $message->storeId);
        self::assertFalse($message->debugMode);
        self::assertCount(1, $message->events);
        self::assertSame('AddToCart', $message->events[0]['event']['event_name']);
        self::assertSame('evt-123', $message->events[0]['event']['event_id']);
        self::assertSame(['currency' => 'USD', 'value' => 9.99], $message->events[0]['custom_data']);
    }

    public function testAddToQueueFallsBackToCurrentStoreWhenStoreIdMissing(): void
    {
        \Mage::$app = new AppStub(42);
        \Mage::$config['42'] = ['meta/conversions/enabled' => '1'];

        $observer = new ObserverAccessor();
        $observer->callAddToQueue(
            [['event' => ['event_name' => 'PageView', 'event_id' => 'evt-x'], 'custom_data' => null]],
            [],
            null,
        );

        self::assertSame(42, \Hirale\Queue\Bus::$dispatches[0]['message']->storeId);
    }

    public function testAddToQueueSkipsEmptyEventList(): void
    {
        $observer = new ObserverAccessor();
        $observer->callAddToQueue([], [], 1);

        self::assertSame([], \Hirale\Queue\Bus::$dispatches);
    }

    public function testAddToQueueSwallowsDispatchExceptions(): void
    {
        \Hirale\Queue\Bus::$nextException = new \RuntimeException('redis down');

        $observer = new ObserverAccessor();
        $observer->callAddToQueue(
            [['event' => ['event_name' => 'AddToCart', 'event_id' => 'evt-1'], 'custom_data' => null]],
            [],
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
        $customData = \Hirale\Queue\Bus::$dispatches[0]['message']->events[0]['custom_data'];
        // 1 added unit * 10.00 = 10.00, NOT the full base row total (30.00).
        self::assertSame(10.0, $customData['value']);
        self::assertSame(1.0, $customData['contents'][0][1]);
    }

    public function testAddToCartReportsTheSameItemOnlyOncePerRequest(): void
    {
        $quote = new QuoteStub([], 0.0, 100, 1);
        \Mage::$singletons['checkout/session'] = new CheckoutSessionStub($quote);

        $item = new CartItemStub(
            sku: 'SKU-1',
            qty: 1.0,
            basePrice: 10.0,
            name: 'Item',
            id: 5,
            quoteId: 100,
            storeId: 1,
        );

        // The same observer instance (a per-request singleton) sees the item
        // saved twice — e.g. once on add and again during totals collection.
        $observer = new \Hirale_MetaConversions_Model_Observer();
        $observer->addToCart(new \Varien_Event_Observer(new \Varien_Event(['item' => $item])));
        $observer->addToCart(new \Varien_Event_Observer(new \Varien_Event(['item' => $item])));

        self::assertCount(1, \Hirale\Queue\Bus::$dispatches);
    }

    public function testAddToWishlistValueAccountsForItemQuantity(): void
    {
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
        $customData = $message->events[0]['custom_data'];
        // 10.00 * 2 = 20.00 — the item quantity is no longer hardcoded to 1.
        self::assertSame(20.0, $customData['value']);
        self::assertSame(2.0, $customData['contents'][0][1]);
        // Per-item store id is resolved (was dead code under method_exists).
        self::assertSame(7, $message->storeId);
    }

    public function testCompleteRegistrationSendsHashedCustomerEmail(): void
    {
        $customer = new CustomerStub(id: 5, email: 'new@example.test', storeId: 7);

        $observer = new \Hirale_MetaConversions_Model_Observer();
        $observer->completeRegistration(new \Varien_Event_Observer(new \Varien_Event(['customer' => $customer])));

        self::assertCount(1, \Hirale\Queue\Bus::$dispatches);
        $message = \Hirale\Queue\Bus::$dispatches[0]['message'];
        self::assertSame(7, $message->storeId);
        self::assertSame('CompleteRegistration', $message->events[0]['event']['event_name']);
        self::assertNull($message->events[0]['custom_data']);
        // PII leaves the request pre-hashed — the queue never sees the address.
        self::assertSame(hash('sha256', 'new@example.test'), $message->userData['email']);
        self::assertSame('5', $message->userData['external_id']);
    }

    public function testDispatchRouteEventBatchesViewContentAndPageViewIntoOneMessage(): void
    {
        \Mage::register('current_product', new ProductStub('SKU-9', 12.5, 'Viewed Product'));
        \Mage::register('current_category', new CategoryStub('Shoes'));

        $observer = new \Hirale_MetaConversions_Model_Observer();
        $observer->dispatchRouteEvent($this->routeObserver(
            'catalog',
            'product',
            'view',
            new ResponseStub(200, ['<!DOCTYPE html><html><body>page</body></html>']),
        ));

        // One queue message (= one Graph API call) carrying both events.
        self::assertCount(1, \Hirale\Queue\Bus::$dispatches);
        $message = \Hirale\Queue\Bus::$dispatches[0]['message'];
        self::assertCount(2, $message->events);
        self::assertSame('ViewContent', $message->events[0]['event']['event_name']);
        self::assertSame(['SKU-9'], $message->events[0]['custom_data']['content_ids']);
        self::assertSame('Shoes', $message->events[0]['custom_data']['content_category']);
        self::assertSame('PageView', $message->events[1]['event']['event_name']);
        self::assertNull($message->events[1]['custom_data']);
        self::assertSame(self::BROWSER_UA, $message->userData['client_user_agent']);
    }

    public function testDispatchRouteEventMatchesLowercaseHtml5Doctype(): void
    {
        // The HTML5-canonical lowercase form used by many themes; the old
        // case-sensitive strpos('<!DOCTYPE html') silently dropped PageView here.
        $observer = new \Hirale_MetaConversions_Model_Observer();
        $observer->dispatchRouteEvent($this->routeObserver(
            'cms',
            'index',
            'index',
            new ResponseStub(200, ['<!doctype html><html><body>page</body></html>']),
        ));

        self::assertCount(1, \Hirale\Queue\Bus::$dispatches);
        $message = \Hirale\Queue\Bus::$dispatches[0]['message'];
        self::assertCount(1, $message->events);
        self::assertSame('PageView', $message->events[0]['event']['event_name']);
    }

    public function testUnmappedRouteOnANonHtmlResponseBuildsNoUserData(): void
    {
        // Structural laziness assertion: prepareUserData() reads the
        // customer/session singleton, and the stubbed Mage::getSingleton()
        // throws for unregistered aliases — so this test only passes when no
        // user data is built for a request that dispatches nothing (redirects,
        // AJAX, error pages).
        unset(\Mage::$singletons['customer/session']);

        $observer = new \Hirale_MetaConversions_Model_Observer();
        $observer->dispatchRouteEvent($this->routeObserver(
            'cms',
            'index',
            'index',
            new ResponseStub(302, ['']),
        ));

        self::assertSame([], \Hirale\Queue\Bus::$dispatches);
    }

    public function testDispatchRouteEventGuardsNonStringSearchQuery(): void
    {
        // ?q[]=x used to put an array into search_string and poison the
        // queue message; the guard folds it to an empty string.
        $observer = new \Hirale_MetaConversions_Model_Observer();
        $observer->dispatchRouteEvent($this->routeObserver(
            'catalogsearch',
            'result',
            'index',
            new ResponseStub(200, ['<!DOCTYPE html><html></html>']),
            ['q' => ['x']],
        ));

        self::assertCount(1, \Hirale\Queue\Bus::$dispatches);
        $message = \Hirale\Queue\Bus::$dispatches[0]['message'];
        self::assertSame('Search', $message->events[0]['event']['event_name']);
        self::assertSame('', $message->events[0]['custom_data']['search_string']);
    }

    public function testDispatchRouteEventSkipsCrawlerUserAgents(): void
    {
        \Mage::$helpers['core/http']->userAgent =
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
        \Mage::register('current_product', new ProductStub('SKU-9', 12.5, 'Viewed Product'));

        $observer = new \Hirale_MetaConversions_Model_Observer();
        $observer->dispatchRouteEvent($this->routeObserver(
            'catalog',
            'product',
            'view',
            new ResponseStub(200, ['<!DOCTYPE html><html></html>']),
        ));

        self::assertSame([], \Hirale\Queue\Bus::$dispatches);
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
    /** @param list<string> $bodySegments */
    private function successRoute(int $statusCode = 200, array $bodySegments = ['<!DOCTYPE html><html></html>']): \Varien_Event_Observer
    {
        return $this->routeObserver('checkout', 'onepage', 'success', new ResponseStub($statusCode, $bodySegments));
    }

    private function sessionWithOrder(string $incrementId = '100000001'): CheckoutSessionStub
    {
        $session = new CheckoutSessionStub(new QuoteStub());
        $session->lastRealOrder = new OrderStub($incrementId);
        \Mage::$singletons['checkout/session'] = $session;

        return $session;
    }

    /** @return list<string> */
    private function dispatchedEventNames(int $dispatch = 0): array
    {
        return array_map(
            static fn(array $entry): string => $entry['event']['event_name'],
            \Hirale\Queue\Bus::$dispatches[$dispatch]['message']->events,
        );
    }

    public function testReloadedSuccessPageReportsNothingAtAll(): void
    {
        // successAction redirects on the second hit, but the route is still
        // checkout_onepage_success and last_real_order_id still resolves the
        // order. Meta cannot absorb the duplicate: each dispatch mints its own
        // event_id, and dedup is keyed on (event_name, event_id).
        $session = $this->sessionWithOrder();

        (new \Hirale_MetaConversions_Model_Observer())->dispatchRouteEvent($this->successRoute(302, ['']));

        self::assertSame([], \Hirale\Queue\Bus::$dispatches);
        self::assertNull(
            $session->getData(\Hirale_MetaConversions_Model_Observer::SESSION_REPORTED_PURCHASE),
            'the response guard must hold before the claim is ever consulted',
        );
        self::assertNull(\Mage::registry('hirale_meta_event_id_Purchase'));
    }

    public function testPurchaseIsReportedOnlyOncePerOrder(): void
    {
        $this->sessionWithOrder();
        $observer = new \Hirale_MetaConversions_Model_Observer();

        $observer->dispatchRouteEvent($this->successRoute());
        $observer->dispatchRouteEvent($this->successRoute());

        self::assertCount(2, \Hirale\Queue\Bus::$dispatches, 'both renders still report the page view');
        self::assertSame(['Purchase', 'PageView'], $this->dispatchedEventNames(0));
        self::assertSame(['PageView'], $this->dispatchedEventNames(1));
    }

    public function testPurchaseIsReportedAgainForADifferentOrder(): void
    {
        $session = $this->sessionWithOrder('100000001');
        $observer = new \Hirale_MetaConversions_Model_Observer();
        $observer->dispatchRouteEvent($this->successRoute());

        $session->lastRealOrder = new OrderStub('100000002');
        $observer->dispatchRouteEvent($this->successRoute());

        self::assertSame(['Purchase', 'PageView'], $this->dispatchedEventNames(1));
    }

    public function testMappedRouteOnARedirectReportsNothing(): void
    {
        // An empty cart bounced back from checkout used to send an
        // InitiateCheckout with a zero value and no contents.
        \Mage::$singletons['checkout/session'] = new CheckoutSessionStub(new QuoteStub());

        (new \Hirale_MetaConversions_Model_Observer())->dispatchRouteEvent(
            $this->routeObserver('checkout', 'onepage', 'index', new ResponseStub(302, [''])),
        );

        self::assertSame([], \Hirale\Queue\Bus::$dispatches);
    }

    public function testMappedRouteOnANonHtml200ReportsNothing(): void
    {
        \Mage::$singletons['checkout/session'] = new CheckoutSessionStub(new QuoteStub());

        (new \Hirale_MetaConversions_Model_Observer())->dispatchRouteEvent(
            $this->routeObserver('checkout', 'cart', 'index', new ResponseStub(200, ['{"ok":true}'])),
        );

        self::assertSame([], \Hirale\Queue\Bus::$dispatches);
    }

    public function testDoctypeInALaterBodySegmentStillCountsAsARenderedPage(): void
    {
        // A BOM, a licence comment or a layout that appends the doctype in a
        // second segment must not silence every event on the page.
        \Mage::$singletons['checkout/session'] = new CheckoutSessionStub(new QuoteStub());

        (new \Hirale_MetaConversions_Model_Observer())->dispatchRouteEvent($this->routeObserver(
            'cms',
            'index',
            'index',
            new ResponseStub(200, [str_repeat(' ', 200), '<!DOCTYPE html><html></html>']),
        ));

        self::assertCount(1, \Hirale\Queue\Bus::$dispatches);
        self::assertSame(['PageView'], $this->dispatchedEventNames());
    }

    /**
     * Analytics must never break the flow it observes: a payload-building
     * failure is logged and dropped, never propagated into a cart save or a
     * registration.
     *
     * @dataProvider guardedEntryPoints
     */
    public function testEntryPointSwallowsPayloadFailures(string $method, callable $eventFactory): void
    {
        \Mage::$helpers['metaconversions'] = new ThrowingHelperStub();
        \Mage::$singletons['checkout/session'] = new CheckoutSessionStub(new QuoteStub());

        (new \Hirale_MetaConversions_Model_Observer())->{$method}($eventFactory());

        self::assertSame([], \Hirale\Queue\Bus::$dispatches);
        self::assertCount(1, \Mage::$exceptions);
        self::assertInstanceOf(\TypeError::class, \Mage::$exceptions[0]);
    }

    /**
     * @return array<string, array{0: string, 1: callable}>
     */
    public static function guardedEntryPoints(): array
    {
        return [
            'addToCart' => ['addToCart', static fn(): \Varien_Event_Observer => new \Varien_Event_Observer(
                new \Varien_Event(['item' => new CartItemStub(sku: 'SKU-1', name: 'Item One', qty: 1.0, basePrice: 10.0)]),
            )],
            'addToWishlist' => ['addToWishlist', static fn(): \Varien_Event_Observer => new \Varien_Event_Observer(
                new \Varien_Event(['items' => [new WishlistItemStub(['store_id' => 1, 'qty' => 1.0, 'product' => new ProductStub('SKU-1', 10.0, 'Item One')])]]),
            )],
            'completeRegistration' => ['completeRegistration', static fn(): \Varien_Event_Observer => new \Varien_Event_Observer(
                new \Varien_Event(['customer' => new CustomerStub()]),
            )],
            'dispatchRouteEvent' => ['dispatchRouteEvent', static fn(): \Varien_Event_Observer => new \Varien_Event_Observer(
                new \Varien_Event(['app' => new RouteAppStub(
                    new RequestStub('cms', 'index', 'index'),
                    new ResponseStub(200, ['<!DOCTYPE html>']),
                )]),
            )],
        ];
    }

}

class ObserverAccessor extends \Hirale_MetaConversions_Model_Observer
{
    /**
     * @param list<array{event: array<string, mixed>, custom_data: array<string, mixed>|null}> $events
     * @param array<string, mixed> $userData
     */
    public function callAddToQueue(array $events, array $userData, ?int $storeId): void
    {
        $this->addToQueue($events, $userData, $storeId);
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
