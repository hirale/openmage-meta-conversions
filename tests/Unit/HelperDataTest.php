<?php

declare(strict_types=1);

namespace HiraleMetaConversions\Tests\Unit;

use HiraleMetaConversions\Tests\Support\CookieStub;
use HiraleMetaConversions\Tests\Support\HttpHelperStub;
use HiraleMetaConversions\Tests\Support\UrlHelperStub;
use PHPUnit\Framework\TestCase;

class HelperDataTest extends TestCase
{
    protected function setUp(): void
    {
        \Mage::reset();
        \Mage::$helpers['core/http'] = new HttpHelperStub();
        \Mage::$helpers['core/url'] = new UrlHelperStub();
        \Mage::$singletons['core/cookie'] = new CookieStub();
        \Mage::$singletons['customer/session'] = new \Mage_Customer_Model_Session();
        \Mage::$config = ['__null__' => [], '1' => [], '7' => []];
    }

    protected function tearDown(): void
    {
        \Mage::reset();
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
}
