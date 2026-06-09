<?php

/**
 * One Meta Conversions API (CAPI) event payload to post. The handler resolves
 * access_token + pixel_id from store config, builds a Facebook SDK Event
 * with UserData and optional CustomData, and posts to graph.facebook.com.
 */
final readonly class Hirale_MetaConversions_Message_CapiEventMessage
{
    /**
     * @param array<string, mixed> $event       CAPI Event object (event_name, event_time, event_id, action_source, event_source_url, ...)
     * @param array<string, mixed> $userData    CAPI UserData object (hashed identifiers, user agent, etc.)
     * @param array<string, mixed>|null $customData Optional CustomData object
     */
    public function __construct(
        public array $event,
        public array $userData,
        public ?array $customData,
        public int $storeId,
        public bool $debugMode = false,
    ) {
    }
}
