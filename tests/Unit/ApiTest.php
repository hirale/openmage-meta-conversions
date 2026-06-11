<?php

declare(strict_types=1);

namespace HiraleMetaConversions\Tests\Unit;

use FacebookAds\Http\Exception\AuthorizationException;
use FacebookAds\Http\Exception\RequestException;
use FacebookAds\Http\Exception\ServerException;
use FacebookAds\Http\Response;
use HiraleMetaConversions\Tests\Support\CookieStub;
use HiraleMetaConversions\Tests\Support\CoreHelperStub;
use HiraleMetaConversions\Tests\Support\HttpHelperStub;
use HiraleMetaConversions\Tests\Support\RecordingApi;
use HiraleMetaConversions\Tests\Support\UrlHelperStub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

class ApiTest extends TestCase
{
    protected function setUp(): void
    {
        \Mage::reset();
        \Mage::$helpers['core'] = new CoreHelperStub();
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

    /**
     * @param list<array{event: array<string, mixed>, custom_data: array<string, mixed>|null}> $events
     */
    private function message(array $events, int $storeId = 1, bool $debugMode = false): \Hirale_MetaConversions_Message_CapiEventMessage
    {
        return new \Hirale_MetaConversions_Message_CapiEventMessage(
            events: $events,
            userData: ['client_ip_address' => '1.2.3.4'],
            storeId: $storeId,
            debugMode: $debugMode,
        );
    }

    /**
     * @param array<string, mixed>|null $customData
     * @return array{event: array<string, mixed>, custom_data: array<string, mixed>|null}
     */
    private function entry(string $eventName, ?array $customData = null): array
    {
        return [
            'event' => ['event_name' => $eventName, 'event_time' => 1700000000, 'event_id' => 'evt-' . $eventName],
            'custom_data' => $customData,
        ];
    }

    public function testInvokeResolvesAccessTokenAndPixelFromMessageStoreId(): void
    {
        \Mage::$config['7']['meta/conversions/access_token'] = 'token-7';
        \Mage::$config['7']['meta/conversions/pixel_id'] = '707';

        $api = new RecordingApi();
        $api($this->message([$this->entry('AddToCart')], storeId: 7));

        self::assertCount(1, $api->sends);
        self::assertSame('token-7', $api->sends[0]['access_token']);
        self::assertSame('707', $api->sends[0]['pixel_id']);
        self::assertCount(1, $api->sends[0]['events']);
    }

    public function testInvokeDecryptsStoredAccessToken(): void
    {
        \Mage::$config['1']['meta/conversions/access_token'] = 'enc:token-1';
        \Mage::$config['1']['meta/conversions/pixel_id'] = '111';

        $api = new RecordingApi();
        $api($this->message([$this->entry('AddToCart')]));

        self::assertSame('token-1', $api->sends[0]['access_token']);
        self::assertSame(['enc:token-1'], \Mage::$helpers['core']->decryptCalls);
    }

    public function testInvokeSkipsWhenAccessTokenMissing(): void
    {
        \Mage::$config['1']['meta/conversions/pixel_id'] = '111';
        // no access_token for store 1

        $api = new RecordingApi();
        $api($this->message([$this->entry('AddToCart')]));

        self::assertSame([], $api->sends);
    }

    public function testInvokeSkipsWhenPixelIdMissing(): void
    {
        \Mage::$config['1']['meta/conversions/access_token'] = 'token-1';
        // no pixel_id for store 1

        $api = new RecordingApi();
        $api($this->message([$this->entry('AddToCart')]));

        self::assertSame([], $api->sends);
    }

    public function testInvokeSendsAllEventsOfTheMessageInOneRequest(): void
    {
        \Mage::$config['1']['meta/conversions/access_token'] = 'token-1';
        \Mage::$config['1']['meta/conversions/pixel_id'] = '111';

        $api = new RecordingApi();
        $api($this->message([
            $this->entry('ViewContent', ['currency' => 'USD', 'value' => 9.99]),
            $this->entry('PageView'),
        ]));

        // One Graph API call carrying both events — not one call per event.
        self::assertCount(1, $api->sends);
        $events = $api->sends[0]['events'];
        self::assertCount(2, $events);
        self::assertSame('ViewContent', $events[0]->getEventName());
        self::assertSame('PageView', $events[1]->getEventName());
        self::assertNotNull($events[0]->getUserData());
        self::assertNotNull($events[1]->getUserData());
    }

    public function testInvokeLogsWhenDebugModeIsSet(): void
    {
        \Mage::$config['1']['meta/conversions/access_token'] = 'token-1';
        \Mage::$config['1']['meta/conversions/pixel_id'] = '111';

        $api = new RecordingApi();
        $api->nextResponse = ['fake' => 'response'];
        $api($this->message([$this->entry('AddToCart')], debugMode: true));

        // Two log calls: the event summary and the response.
        self::assertCount(2, \Mage::$logs);
        self::assertSame(\Hirale_MetaConversions_Model_Api::LOG_FILE, \Mage::$logs[0]['file']);
    }

    public function testInvokeDebugLogNeverContainsUserData(): void
    {
        \Mage::$config['1']['meta/conversions/access_token'] = 'token-1';
        \Mage::$config['1']['meta/conversions/pixel_id'] = '111';

        $api = new RecordingApi();
        $api($this->message([$this->entry('AddToCart')], debugMode: true));

        foreach (\Mage::$logs as $log) {
            self::assertStringNotContainsString('1.2.3.4', print_r($log['message'], true));
        }
    }

    public function testInvokePassesDebugFlagToSendForLoggerGating(): void
    {
        \Mage::$config['1']['meta/conversions/access_token'] = 'token-1';
        \Mage::$config['1']['meta/conversions/pixel_id'] = '111';

        $api = new RecordingApi();
        $api($this->message([$this->entry('AddToCart')], debugMode: true));

        self::assertTrue($api->sends[0]['debug_mode']);
    }

    public function testInvokeDefaultsDebugFlagOff(): void
    {
        \Mage::$config['1']['meta/conversions/access_token'] = 'token-1';
        \Mage::$config['1']['meta/conversions/pixel_id'] = '111';

        $api = new RecordingApi();
        $api($this->message([$this->entry('AddToCart')]));

        self::assertFalse($api->sends[0]['debug_mode']);
    }

    public function testInvokeProcessesCustomDataContentsThroughHelper(): void
    {
        \Mage::$config['1']['meta/conversions/access_token'] = 'token-1';
        \Mage::$config['1']['meta/conversions/pixel_id'] = '111';

        $api = new RecordingApi();
        $api($this->message([
            $this->entry('AddToCart', [
                'currency' => 'USD',
                'value' => 9.99,
                'contents' => [['SKU-1', 2, 9.99, 'Test Item']],
            ]),
        ]));

        self::assertCount(1, $api->sends);

        // Assert the raw tuple was actually mapped into a Content object via the
        // helper, not silently dropped (assertCount alone would not catch that).
        $customData = $api->sends[0]['events'][0]->getCustomData();
        self::assertNotNull($customData);
        $contents = $customData->getContents();
        self::assertCount(1, $contents);
        self::assertSame('SKU-1', $contents[0]->getProductId());
        self::assertSame('Test Item', $contents[0]->getTitle());
        self::assertEquals(2, $contents[0]->getQuantity());
    }

    public function testInvokeMarksAuthErrorsUnrecoverable(): void
    {
        \Mage::$config['1']['meta/conversions/access_token'] = 'token-1';
        \Mage::$config['1']['meta/conversions/pixel_id'] = '111';

        $api = new RecordingApi();
        $api->nextThrowable = new AuthorizationException($this->graphErrorResponse(401, 'Invalid OAuth access token.', 'OAuthException', 190));

        try {
            $api($this->message([$this->entry('AddToCart')]));
            self::fail('Expected UnrecoverableMessageHandlingException was not thrown.');
        } catch (UnrecoverableMessageHandlingException $e) {
            self::assertInstanceOf(AuthorizationException::class, $e->getPrevious());
        }

        // The permanent drop must leave an operator-visible trace.
        self::assertCount(1, \Mage::$logs);
        self::assertStringContainsString('Invalid OAuth access token.', (string) \Mage::$logs[0]['message']);
    }

    public function testInvokeLetsTransientErrorsBubbleForRetry(): void
    {
        \Mage::$config['1']['meta/conversions/access_token'] = 'token-1';
        \Mage::$config['1']['meta/conversions/pixel_id'] = '111';

        $api = new RecordingApi();
        $api->nextThrowable = new ServerException($this->graphErrorResponse(500, 'An unknown error occurred', null, 1));

        $this->expectException(RequestException::class);
        $api($this->message([$this->entry('AddToCart')]));
    }

    private function graphErrorResponse(int $statusCode, string $message, ?string $type, int $code): Response
    {
        $response = new Response();
        $response->setStatusCode($statusCode);
        $response->setBody((string) json_encode([
            'error' => ['message' => $message, 'type' => $type, 'code' => $code],
        ]));

        return $response;
    }
}
