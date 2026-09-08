<?php

declare(strict_types=1);

namespace HiraleMetaConversions\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the packaging seams that decide which queue backend a platform gets.
 * They live in JSON/XML, so nothing else in the suite would catch a regression.
 */
class PackageMetadataTest extends TestCase
{
    public function testQueueBackendIsNotARuntimeRequirement(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true);

        self::assertIsArray($composer);
        // OpenMage users pull the queue backend in themselves; Maho dispatches
        // through core Maho_Queue and must not drag hirale/queue along.
        self::assertArrayNotHasKey('hirale/queue', $composer['require']);
        self::assertArrayHasKey('hirale/queue', $composer['suggest']);
        self::assertArrayNotHasKey('mahocommerce/maho', $composer['require']);
    }

    public function testModuleDeclarationHasNoQueueDependency(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../../app/etc/modules/Hirale_MetaConversions.xml');

        self::assertNotFalse($xml);
        // A hard Hirale_Queue dependency aborts config loading on a Maho store,
        // where the module dispatches through core Maho_Queue instead.
        self::assertFalse(isset($xml->modules->Hirale_MetaConversions->depends->Hirale_Queue));
        self::assertSame('community', (string) $xml->modules->Hirale_MetaConversions->codePool);
    }

    public function testConfigRegistersBothQueueBackendsOnTheAnalyticsQueue(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../../app/code/community/Hirale/MetaConversions/etc/config.xml');

        self::assertNotFalse($xml);
        self::assertSame(
            \Hirale_MetaConversions_Helper_Data::QUEUE_ANALYTICS,
            (string) $xml->global->hirale_queue->routing->Hirale_MetaConversions_Message_CapiEventMessage,
        );
        self::assertSame(
            'metaconversions/api',
            (string) $xml->global->hirale_queue->handlers->Hirale_MetaConversions_Message_CapiEventMessage,
        );
        // Maho pool routing: one outbound HTTP call per message never belongs
        // in the resident fast pool.
        self::assertSame('slow', (string) $xml->global->queue->routing->analytics);
    }

    public function testHandlerCarriesTheMahoMessageHandlerAttribute(): void
    {
        $method = new \ReflectionMethod(\Hirale_MetaConversions_Model_Api::class, '__invoke');

        self::assertNotEmpty(
            $method->getAttributes(\Maho\Config\MessageHandler::class),
            'Maho compiles this attribute into vendor/composer/maho_attributes.php at dump-autoload time',
        );
    }

    public function testQueuedMessageIsSerializable(): void
    {
        // Maho's DB transport stores the message with serialize(); an SDK
        // object anywhere in the payload would break decoding on the worker.
        $message = new \Hirale_MetaConversions_Message_CapiEventMessage(
            events: [['event' => ['event_name' => 'Purchase', 'event_time' => 1700000000], 'custom_data' => ['value' => 9.99]]],
            userData: ['client_ip_address' => '1.2.3.4', 'em' => str_repeat('a', 64)],
            storeId: 1,
        );

        $restored = unserialize(serialize($message));

        self::assertInstanceOf(\Hirale_MetaConversions_Message_CapiEventMessage::class, $restored);
        self::assertEquals($message, $restored);
    }
}
