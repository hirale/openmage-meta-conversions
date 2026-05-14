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

    public function testHandleResolvesAccessTokenAndPixelFromPayloadStoreId(): void
    {
        \Mage::$config['7']['meta/conversions/access_token'] = 'token-7';
        \Mage::$config['7']['meta/conversions/pixel_id'] = '707';

        $api = new RecordingApi();
        $api->handle([
            'data' => [
                'event' => ['event_name' => 'AddToCart', 'event_time' => 1700000000, 'event_id' => 'evt-1'],
                'userData' => ['client_ip_address' => '1.2.3.4'],
                '_store_id' => 7,
            ],
        ]);

        self::assertCount(1, $api->sends);
        self::assertSame('token-7', $api->sends[0]['access_token']);
        self::assertSame('707', $api->sends[0]['pixel_id']);
    }

    public function testHandleSkipsWhenAccessTokenMissing(): void
    {
        \Mage::$config['1']['meta/conversions/pixel_id'] = '111';
        // no access_token for store 1

        $api = new RecordingApi();
        $api->handle([
            'data' => [
                'event' => ['event_name' => 'AddToCart', 'event_id' => 'evt-1'],
                'userData' => [],
                '_store_id' => 1,
            ],
        ]);

        self::assertSame([], $api->sends);
    }

    public function testHandleSkipsWhenPixelIdMissing(): void
    {
        \Mage::$config['1']['meta/conversions/access_token'] = 'token-1';
        // no pixel_id for store 1

        $api = new RecordingApi();
        $api->handle([
            'data' => [
                'event' => ['event_name' => 'AddToCart', 'event_id' => 'evt-1'],
                'userData' => [],
                '_store_id' => 1,
            ],
        ]);

        self::assertSame([], $api->sends);
    }

    public function testHandleLogsWhenDebugModeIsSet(): void
    {
        \Mage::$config['1']['meta/conversions/access_token'] = 'token-1';
        \Mage::$config['1']['meta/conversions/pixel_id'] = '111';

        $api = new RecordingApi();
        $api->nextResponse = ['fake' => 'response'];
        $api->handle([
            'data' => [
                'event' => ['event_name' => 'AddToCart', 'event_id' => 'evt-1'],
                'userData' => [],
                '_store_id' => 1,
                '_debug_mode' => true,
            ],
        ]);

        // Two log calls: the Event object and the response (matches existing behavior).
        self::assertCount(2, \Mage::$logs);
    }

    public function testHandleProcessesCustomDataContentsThroughHelper(): void
    {
        \Mage::$config['1']['meta/conversions/access_token'] = 'token-1';
        \Mage::$config['1']['meta/conversions/pixel_id'] = '111';

        $api = new RecordingApi();
        $api->handle([
            'data' => [
                'event' => ['event_name' => 'AddToCart', 'event_id' => 'evt-1'],
                'userData' => [],
                'customData' => [
                    'currency' => 'USD',
                    'value' => 9.99,
                    'contents' => [['SKU-1', 1, 9.99, 'Test Item']],
                ],
                '_store_id' => 1,
            ],
        ]);

        self::assertCount(1, $api->sends);
    }
}
