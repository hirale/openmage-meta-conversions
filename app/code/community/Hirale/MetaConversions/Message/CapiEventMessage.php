<?php

declare(strict_types=1);

/**
 * A batch of Meta Conversions API (CAPI) events captured during one frontend
 * request, sharing one set of user-matching data. The handler resolves
 * access_token + pixel_id from store config, builds one Facebook SDK
 * EventRequest carrying every event, and posts them to graph.facebook.com in
 * a single Graph API call (CAPI accepts up to 1000 events per request).
 */
final readonly class Hirale_MetaConversions_Message_CapiEventMessage
{
    /**
     * @param list<array{event: array<string, mixed>, custom_data: array<string, mixed>|null}> $events
     *        CAPI Event envelopes (event_name, event_time, event_id, action_source,
     *        event_source_url, ...), each with its own optional CustomData payload.
     * @param array<string, mixed> $userData CAPI UserData shared by all events of the
     *        request. PII fields are normalized + SHA-256 hashed at capture time;
     *        client_ip_address / client_user_agent / fbp / fbc stay raw per Meta spec.
     */
    public function __construct(
        public array $events,
        public array $userData,
        public int $storeId,
        public bool $debugMode = false,
    ) {
    }
}
