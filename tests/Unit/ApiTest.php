<?php

declare(strict_types=1);

namespace HiraleMetaConversions\Tests\Unit;

use HiraleMetaConversions\Tests\Support\CookieStub;
use HiraleMetaConversions\Tests\Support\HttpHelperStub;
use HiraleMetaConversions\Tests\Support\RecordingApi;
use HiraleMetaConversions\Tests\Support\UrlHelperStub;
use PHPUnit\Framework\TestCase;

class ApiTest extends TestCase
{
    protected function setUp(): void
    {
        \Mage::reset();
        \Mage::$helpers['core/http'] = new HttpHelperStub();
        \Mage::$helpers['core/url'] = new UrlHelperStub();
        \Mage::$singletons['core/cookie'] = new CookieStub();
        \Mage::$singletons['customer/session'] = new \Mage_Customer_Model_Session();
        \Mage::$helpers['metaconversions'] = new \Hirale_MetaConversions_Helper_Data();
        \Mage::$config = ['__null__' => [], '1' => [], '7' => []];
    }

    protected function tearDown(): void
    {
        \Mage::reset();
    }

    public function testInvokeResolvesAccessTokenAndPixelFromMessageStoreId(): void
    {
        \Mage::$config['7']['meta/conversions/access_token'] = 'token-7';
        \Mage::$config['7']['meta/conversions/pixel_id'] = '707';

        $api = new RecordingApi();
        $api(new \Hirale_MetaConversions_Message_CapiEventMessage(
            event: ['event_name' => 'AddToCart', 'event_time' => 1700000000, 'event_id' => 'evt-1'],
            userData: ['client_ip_address' => '1.2.3.4'],
            customData: null,
            storeId: 7,
        ));

        self::assertCount(1, $api->sends);
        self::assertSame('token-7', $api->sends[0]['access_token']);
        self::assertSame('707', $api->sends[0]['pixel_id']);
    }

    public function testInvokeSkipsWhenAccessTokenMissing(): void
    {
        \Mage::$config['1']['meta/conversions/pixel_id'] = '111';
        // no access_token for store 1

        $api = new RecordingApi();
        $api(new \Hirale_MetaConversions_Message_CapiEventMessage(
            event: ['event_name' => 'AddToCart', 'event_id' => 'evt-1'],
            userData: [],
            customData: null,
            storeId: 1,
        ));

        self::assertSame([], $api->sends);
    }

    public function testInvokeSkipsWhenPixelIdMissing(): void
    {
        \Mage::$config['1']['meta/conversions/access_token'] = 'token-1';
        // no pixel_id for store 1

        $api = new RecordingApi();
        $api(new \Hirale_MetaConversions_Message_CapiEventMessage(
            event: ['event_name' => 'AddToCart', 'event_id' => 'evt-1'],
            userData: [],
            customData: null,
            storeId: 1,
        ));

        self::assertSame([], $api->sends);
    }

    public function testInvokeLogsWhenDebugModeIsSet(): void
    {
        \Mage::$config['1']['meta/conversions/access_token'] = 'token-1';
        \Mage::$config['1']['meta/conversions/pixel_id'] = '111';

        $api = new RecordingApi();
        $api->nextResponse = ['fake' => 'response'];
        $api(new \Hirale_MetaConversions_Message_CapiEventMessage(
            event: ['event_name' => 'AddToCart', 'event_id' => 'evt-1'],
            userData: [],
            customData: null,
            storeId: 1,
            debugMode: true,
        ));

        // Two log calls: the Event object and the response (matches existing behavior).
        self::assertCount(2, \Mage::$logs);
    }

    public function testInvokePassesDebugFlagToSendForLoggerGating(): void
    {
        \Mage::$config['1']['meta/conversions/access_token'] = 'token-1';
        \Mage::$config['1']['meta/conversions/pixel_id'] = '111';

        $api = new RecordingApi();
        $api(new \Hirale_MetaConversions_Message_CapiEventMessage(
            event: ['event_name' => 'AddToCart', 'event_id' => 'evt-1'],
            userData: [],
            customData: null,
            storeId: 1,
            debugMode: true,
        ));

        self::assertTrue($api->sends[0]['debug_mode']);
    }

    public function testInvokeDefaultsDebugFlagOff(): void
    {
        \Mage::$config['1']['meta/conversions/access_token'] = 'token-1';
        \Mage::$config['1']['meta/conversions/pixel_id'] = '111';

        $api = new RecordingApi();
        $api(new \Hirale_MetaConversions_Message_CapiEventMessage(
            event: ['event_name' => 'AddToCart', 'event_id' => 'evt-1'],
            userData: [],
            customData: null,
            storeId: 1,
        ));

        self::assertFalse($api->sends[0]['debug_mode']);
    }

    public function testInvokeProcessesCustomDataContentsThroughHelper(): void
    {
        \Mage::$config['1']['meta/conversions/access_token'] = 'token-1';
        \Mage::$config['1']['meta/conversions/pixel_id'] = '111';

        $api = new RecordingApi();
        $api(new \Hirale_MetaConversions_Message_CapiEventMessage(
            event: ['event_name' => 'AddToCart', 'event_id' => 'evt-1'],
            userData: [],
            customData: [
                'currency' => 'USD',
                'value' => 9.99,
                'contents' => [['SKU-1', 2, 9.99, 'Test Item']],
            ],
            storeId: 1,
        ));

        self::assertCount(1, $api->sends);

        // Assert the raw tuple was actually mapped into a Content object via the
        // helper, not silently dropped (assertCount alone would not catch that).
        $customData = $api->sends[0]['event']->getCustomData();
        self::assertNotNull($customData);
        $contents = $customData->getContents();
        self::assertCount(1, $contents);
        self::assertSame('SKU-1', $contents[0]->getProductId());
        self::assertSame('Test Item', $contents[0]->getTitle());
        self::assertEquals(2, $contents[0]->getQuantity());
    }
}
