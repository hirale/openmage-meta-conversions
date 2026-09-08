<?php

declare(strict_types=1);

namespace HiraleMetaConversions\Tests\Unit;

use FacebookAds\Object\ServerSide\Gender;
use HiraleMetaConversions\Tests\Support\AddressStub;
use HiraleMetaConversions\Tests\Support\CookieStub;
use HiraleMetaConversions\Tests\Support\CoreHelperStub;
use HiraleMetaConversions\Tests\Support\CustomerStub;
use HiraleMetaConversions\Tests\Support\HttpHelperStub;
use HiraleMetaConversions\Tests\Support\UrlHelperStub;
use Hirale\Queue\Bus;
use Maho\Queue\QueueManager;
use PHPUnit\Framework\TestCase;

class HelperDataTest extends TestCase
{
    protected function setUp(): void
    {
        \Mage::reset();
        Bus::reset();
        QueueManager::reset();
        \Mage::$helpers['core'] = new CoreHelperStub();
        \Mage::$helpers['core/http'] = new HttpHelperStub();
        \Mage::$helpers['core/url'] = new UrlHelperStub();
        \Mage::$singletons['core/cookie'] = new CookieStub();
        \Mage::$singletons['customer/session'] = new \Mage_Customer_Model_Session();
        \Mage::$config = ['__null__' => [], '1' => [], '7' => []];
    }

    protected function tearDown(): void
    {
        \Mage::reset();
        Bus::reset();
        QueueManager::reset();
    }

    public function testIsConversionsEnabledReadsStoreScopedConfig(): void
    {
        \Mage::$config['1']['meta/conversions/enabled'] = '1';
        \Mage::$config['7']['meta/conversions/enabled'] = '0';

        $helper = new \Hirale_MetaConversions_Helper_Data();

        self::assertTrue($helper->isConversionsEnabled(1));
        self::assertFalse($helper->isConversionsEnabled(7));
    }

    public function testGetAccessTokenIsPerStore(): void
    {
        \Mage::$config['1']['meta/conversions/access_token'] = 'token-1';
        \Mage::$config['7']['meta/conversions/access_token'] = 'token-7';

        $helper = new \Hirale_MetaConversions_Helper_Data();

        self::assertSame('token-1', $helper->getAccessToken(1));
        self::assertSame('token-7', $helper->getAccessToken(7));
        // Re-read: cache must not leak across stores.
        self::assertSame('token-1', $helper->getAccessToken(1));
    }

    public function testGetAccessTokenDecryptsTheStoredValue(): void
    {
        // The admin field uses the encrypted backend model, so the raw config
        // value is ciphertext; the helper must run it through core/decrypt.
        \Mage::$config['1']['meta/conversions/access_token'] = 'enc:secret-token';

        $helper = new \Hirale_MetaConversions_Helper_Data();

        self::assertSame('secret-token', $helper->getAccessToken(1));
        self::assertSame(['enc:secret-token'], \Mage::$helpers['core']->decryptCalls);
    }

    public function testGetAccessTokenIsNullWhenDecryptionYieldsEmptyValue(): void
    {
        \Mage::$config['1']['meta/conversions/access_token'] = 'enc:';

        $helper = new \Hirale_MetaConversions_Helper_Data();

        self::assertNull($helper->getAccessToken(1));
    }

    public function testGetPixelIdIsPerStoreAndNullWhenUnset(): void
    {
        \Mage::$config['1']['meta/conversions/pixel_id'] = '111';

        $helper = new \Hirale_MetaConversions_Helper_Data();

        self::assertSame('111', $helper->getPixelId(1));
        self::assertNull($helper->getPixelId(7));
    }

    public function testIsDebugModeIsPerStore(): void
    {
        \Mage::$config['1']['meta/conversions/debug_mode'] = '1';
        $helper = new \Hirale_MetaConversions_Helper_Data();

        self::assertTrue($helper->isDebugMode(1));
        self::assertFalse($helper->isDebugMode(7));
    }

    public function testGetEventIdReturnsRegistryValueWhenSet(): void
    {
        \Mage::register('hirale_meta_event_id_AddToCart', 'pixel-side-id-abc');

        $helper = new \Hirale_MetaConversions_Helper_Data();

        self::assertSame('pixel-side-id-abc', $helper->getEventId('AddToCart'));
    }

    public function testGetEventIdGeneratesUniqueWhenRegistryEmpty(): void
    {
        $helper = new \Hirale_MetaConversions_Helper_Data();

        $a = $helper->getEventId('AddToCart');
        $b = $helper->getEventId('AddToCart');

        self::assertNotSame('', $a);
        self::assertNotSame($a, $b, 'Without a registry value each call must produce a fresh id.');
    }

    public function testGetEventIdIgnoresRegistryValuesForOtherEventNames(): void
    {
        \Mage::register('hirale_meta_event_id_AddToCart', 'fixed-id');

        $helper = new \Hirale_MetaConversions_Helper_Data();

        self::assertSame('fixed-id', $helper->getEventId('AddToCart'));
        self::assertNotSame('fixed-id', $helper->getEventId('Purchase'));
    }

    public function testPrepareUserDataReadsFbCookiesAndCurrentVisitor(): void
    {
        \Mage::$singletons['core/cookie']->values = ['_fbp' => 'fb.1.aaa', '_fbc' => 'fb.1.bbb'];

        $helper = new \Hirale_MetaConversions_Helper_Data();
        $data = $helper->prepareUserData();

        self::assertSame('fb.1.aaa', $data['fbp']);
        self::assertSame('fb.1.bbb', $data['fbc']);
        self::assertSame('127.0.0.1', $data['client_ip_address']);
    }

    public function testFormatPriceRoundsToTwoDecimals(): void
    {
        $helper = new \Hirale_MetaConversions_Helper_Data();

        self::assertSame(1.23, $helper->formatPrice(1.234));
        self::assertSame(1.24, $helper->formatPrice(1.236));
    }

    public function testPrepareUserDataMapsMaleGender(): void
    {
        $helper = new \Hirale_MetaConversions_Helper_Data();
        $data = $helper->prepareUserData(new CustomerStub(gender: 1));

        self::assertSame(hash('sha256', Gender::MALE), $data['gender']);
    }

    public function testPrepareUserDataMapsFemaleGender(): void
    {
        $helper = new \Hirale_MetaConversions_Helper_Data();
        // Magento gender 2 is female; the old truthy check wrongly reported male.
        $data = $helper->prepareUserData(new CustomerStub(gender: 2));

        self::assertSame(hash('sha256', Gender::FEMALE), $data['gender']);
    }

    public function testPrepareUserDataOmitsGenderWhenUnspecified(): void
    {
        $helper = new \Hirale_MetaConversions_Helper_Data();
        $data = $helper->prepareUserData(new CustomerStub(gender: 0));

        self::assertArrayNotHasKey('gender', $data);
    }

    public function testPrepareUserDataNormalizesDateOfBirthToYyyymmdd(): void
    {
        $helper = new \Hirale_MetaConversions_Helper_Data();
        $data = $helper->prepareUserData(new CustomerStub(dateOfBirth: '1990-05-15'));

        self::assertSame(hash('sha256', '19900515'), $data['date_of_birth']);
    }

    public function testPrepareUserDataOmitsDateOfBirthWhenEmpty(): void
    {
        $helper = new \Hirale_MetaConversions_Helper_Data();
        $data = $helper->prepareUserData(new CustomerStub(dateOfBirth: ''));

        self::assertArrayNotHasKey('date_of_birth', $data);
    }

    public function testPrepareUserDataOmitsMysqlZeroDateOfBirth(): void
    {
        $helper = new \Hirale_MetaConversions_Helper_Data();
        // strtotime('0000-00-00') used to yield the bogus '-00011130'; the
        // strict parser must reject the MySQL zero date instead.
        $data = $helper->prepareUserData(new CustomerStub(dateOfBirth: '0000-00-00'));

        self::assertArrayNotHasKey('date_of_birth', $data);
    }

    public function testPrepareUserDataRejectsAmbiguousNonIsoDateOfBirth(): void
    {
        $helper = new \Hirale_MetaConversions_Helper_Data();
        // '06/05/1990' is ambiguous; strtotime would silently misread it.
        $data = $helper->prepareUserData(new CustomerStub(dateOfBirth: '06/05/1990'));

        self::assertArrayNotHasKey('date_of_birth', $data);
    }

    public function testPrepareUserDataNormalizesDateOfBirthWithTimeComponent(): void
    {
        $helper = new \Hirale_MetaConversions_Helper_Data();
        $data = $helper->prepareUserData(new CustomerStub(dateOfBirth: '1990-05-15 00:00:00'));

        self::assertSame(hash('sha256', '19900515'), $data['date_of_birth']);
    }

    public function testPrepareUserDataRejectsImplausibleFutureDateOfBirth(): void
    {
        $helper = new \Hirale_MetaConversions_Helper_Data();
        $data = $helper->prepareUserData(new CustomerStub(dateOfBirth: '2099-01-01'));

        self::assertArrayNotHasKey('date_of_birth', $data);
    }

    public function testPrepareUserDataIncludesExternalIdForKnownCustomer(): void
    {
        $helper = new \Hirale_MetaConversions_Helper_Data();
        $data = $helper->prepareUserData(new CustomerStub(id: 42));

        self::assertSame('42', $data['external_id']);
    }

    public function testPrepareUserDataOmitsExternalIdWhenCustomerHasNoId(): void
    {
        $helper = new \Hirale_MetaConversions_Helper_Data();
        $data = $helper->prepareUserData(new CustomerStub());

        self::assertArrayNotHasKey('external_id', $data);
    }

    public function testPrepareUserDataHashesNormalizedEmailAtCaptureTime(): void
    {
        $helper = new \Hirale_MetaConversions_Helper_Data();
        // Mixed case + padding: the SDK normalizer lowercases and trims before
        // hashing, so the digest matches what worker-side hashing would send.
        $data = $helper->prepareUserData(new CustomerStub(email: ' John.Doe@Example.COM '));

        self::assertSame(hash('sha256', 'john.doe@example.com'), $data['email']);
    }

    public function testPrepareUserDataOmitsMalformedEmailInsteadOfFailing(): void
    {
        $helper = new \Hirale_MetaConversions_Helper_Data();
        // The SDK normalizer throws on invalid emails; the helper must drop
        // the field rather than let the exception break the storefront request.
        $data = $helper->prepareUserData(new CustomerStub(email: 'not-an-email'));

        self::assertArrayNotHasKey('email', $data);
    }

    public function testPrepareUserDataOmitsEmptyPiiFieldsEntirely(): void
    {
        $helper = new \Hirale_MetaConversions_Helper_Data();
        // Hashing '' would send a junk-match digest; empty PII is omitted.
        $data = $helper->prepareUserData(new CustomerStub(email: '', firstname: '', lastname: ''));

        self::assertArrayNotHasKey('email', $data);
        self::assertArrayNotHasKey('first_name', $data);
        self::assertArrayNotHasKey('last_name', $data);
    }

    public function testPrepareUserDataHashesBillingAddressFields(): void
    {
        $helper = new \Hirale_MetaConversions_Helper_Data();
        $data = $helper->prepareUserData(new CustomerStub(
            billingAddress: new AddressStub(
                telephone: '15551234567',
                city: 'New York',
                region: 'Alaska',
                postcode: '10010',
                countryId: 'US',
            ),
        ));

        // Each value runs through the SDK's own field normalization first
        // (digits-only phone, lowercase alnum city, two-letter country, ...).
        self::assertSame(hash('sha256', '15551234567'), $data['phone']);
        self::assertSame(hash('sha256', 'newyork'), $data['city']);
        self::assertSame(hash('sha256', 'alaska'), $data['state']);
        self::assertSame(hash('sha256', '10010'), $data['zip_code']);
        self::assertSame(hash('sha256', 'us'), $data['country_code']);
    }

    public function testPrepareUserDataLeavesNonPiiMatchingKeysRaw(): void
    {
        \Mage::$singletons['core/cookie']->values = ['_fbp' => 'fb.1.aaa'];

        $helper = new \Hirale_MetaConversions_Helper_Data();
        $data = $helper->prepareUserData(new CustomerStub(id: 42));

        // Meta requires these unhashed; external_id stays raw so it keeps
        // matching the raw id the browser-side Pixel sends.
        self::assertSame('127.0.0.1', $data['client_ip_address']);
        self::assertSame('fb.1.aaa', $data['fbp']);
        self::assertSame('42', $data['external_id']);
    }

    public function testBridgePrefersMahoCoreQueueWhenTheModuleIsEnabled(): void
    {
        \Mage::$enabledModules['Maho_Queue'] = true;

        $helper = new \Hirale_MetaConversions_Helper_Data();

        self::assertTrue($helper->isQueueEnabled());
        self::assertTrue($helper->enqueueCapiEvents(
            [['event' => ['event_name' => 'AddToCart'], 'custom_data' => null]],
            ['client_ip_address' => '1.2.3.4'],
            7,
            false,
        ));
        self::assertCount(1, QueueManager::$dispatches);
        self::assertSame([], Bus::$dispatches);

        $call = QueueManager::$dispatches[0];
        self::assertSame(\Hirale_MetaConversions_Helper_Data::QUEUE_ANALYTICS, $call['queue']);
        self::assertNull($call['delaySeconds']);

        $message = $call['message'];
        self::assertInstanceOf(\Hirale_MetaConversions_Message_CapiEventMessage::class, $message);
        self::assertSame('AddToCart', $message->events[0]['event']['event_name']);
        self::assertSame(['client_ip_address' => '1.2.3.4'], $message->userData);
        self::assertSame(7, $message->storeId);
        self::assertFalse($message->debugMode);
    }

    public function testBridgeFallsBackToHiraleQueueWhenMahoQueueIsDisabled(): void
    {
        // The stubbed QueueManager class exists either way, so the module flag
        // is what actually decides the branch.
        $helper = new \Hirale_MetaConversions_Helper_Data();

        self::assertTrue($helper->isQueueEnabled());
        self::assertTrue($helper->enqueueCapiEvents([['event' => ['event_name' => 'Purchase'], 'custom_data' => null]], [], 1, true));
        self::assertCount(1, Bus::$dispatches);
        self::assertSame([], QueueManager::$dispatches);

        // hirale/queue routes by message class from its own config.xml.
        self::assertSame('dispatch', Bus::$dispatches[0]['method']);
        self::assertTrue(Bus::$dispatches[0]['message']->debugMode);
    }

    public function testBridgeReportsQueueUnavailableWithoutAnyBackend(): void
    {
        $helper = new class extends \Hirale_MetaConversions_Helper_Data {
            #[\Override]
            protected function _isHiraleQueueAvailable(): bool
            {
                return false;
            }
        };

        self::assertFalse($helper->isQueueEnabled());
        self::assertFalse($helper->enqueueCapiEvents([['event' => ['event_name' => 'ViewContent'], 'custom_data' => null]], [], 1, false));
        self::assertSame([], Bus::$dispatches);
        self::assertSame([], QueueManager::$dispatches);
        // Silently declining is the point: no exception reaches the observer.
        self::assertSame([], \Mage::$exceptions);
    }

    public function testDispatchFailureIsSwallowedAndLogged(): void
    {
        \Mage::$enabledModules['Maho_Queue'] = true;
        QueueManager::$nextException = new \RuntimeException('queue table is gone');

        $helper = new \Hirale_MetaConversions_Helper_Data();

        self::assertFalse($helper->enqueueCapiEvents([['event' => ['event_name' => 'Lead'], 'custom_data' => null]], [], 1, false));
        self::assertCount(1, \Mage::$exceptions);
        self::assertSame('queue table is gone', \Mage::$exceptions[0]->getMessage());
    }
}
