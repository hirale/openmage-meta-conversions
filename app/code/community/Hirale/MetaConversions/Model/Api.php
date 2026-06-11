<?php

declare(strict_types=1);

use FacebookAds\Api;
use FacebookAds\Http\Exception\AuthorizationException;
use FacebookAds\Http\Exception\PermissionException;
use FacebookAds\Logger\CurlLogger;
use FacebookAds\Object\ServerSide\CustomData;
use FacebookAds\Object\ServerSide\Event;
use FacebookAds\Object\ServerSide\EventRequest;
use FacebookAds\Object\ServerSide\UserData;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

class Hirale_MetaConversions_Model_Api
{
    public const LOG_FILE = 'meta_conversions.log';

    private ?Hirale_MetaConversions_Helper_Data $_helper = null;

    public function __invoke(Hirale_MetaConversions_Message_CapiEventMessage $message): void
    {
        $helper = $this->_getHelper();
        $accessToken = $helper->getAccessToken($message->storeId);
        $pixelId = $helper->getPixelId($message->storeId);

        if ($accessToken === null || $pixelId === null) {
            Mage::log(
                sprintf(
                    'Dropped %d CAPI event(s): access token or pixel id not configured for store %d.',
                    count($message->events),
                    $message->storeId,
                ),
                null,
                self::LOG_FILE,
                $message->debugMode,
            );
            return;
        }

        $events = [];
        foreach ($message->events as $entry) {
            $eventData = $entry['event'] ?? null;
            if (!is_array($eventData)) {
                continue;
            }

            $event = new Event($eventData);
            $event->setUserData(new UserData($message->userData));

            $customData = $entry['custom_data'] ?? null;
            if (is_array($customData)) {
                $contents = [];
                if (isset($customData['contents']) && is_array($customData['contents'])) {
                    foreach ($customData['contents'] as $content) {
                        if (is_array($content)) {
                            $contents[] = $helper->prepareContent($content);
                        }
                    }
                }
                $customData['contents'] = $contents;
                $event->setCustomData(new CustomData($customData));
            }

            $events[] = $event;
        }

        if ($events === []) {
            return;
        }

        try {
            $response = $this->_sendEvents($accessToken, $pixelId, $events, $message->debugMode);
        } catch (AuthorizationException|PermissionException $e) {
            // A bad token or revoked permission cannot be fixed by retrying;
            // fail the message permanently instead of burning the queue's
            // retry schedule on the whole backlog. Transient errors
            // (ThrottleException, ServerException, network) bubble for retry.
            Mage::log(
                sprintf(
                    'Dropped %d CAPI event(s) for store %d, Meta rejected the credentials: %s',
                    count($events),
                    $message->storeId,
                    $e->getMessage(),
                ),
                null,
                self::LOG_FILE,
                true,
            );
            throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
        }

        if ($message->debugMode) {
            // The raw message entries are logged instead of the SDK Event
            // objects: they carry the event envelopes + custom data but never
            // user_data, so no identifiers (hashed or raw ip/ua/fbp/fbc) land
            // in the log file.
            Mage::log(['store_id' => $message->storeId, 'events' => $message->events], null, self::LOG_FILE, true);
            Mage::log($response, null, self::LOG_FILE, true);
        }
    }

    /**
     * Build the CAPI request and execute it — every event of the message goes
     * out in one Graph API call. Factored out so unit tests can intercept
     * without hitting graph.facebook.com. The curl logger is only attached in
     * debug mode to keep the queue worker output clean on the happy path.
     *
     * @param list<Event> $events
     */
    protected function _sendEvents(string $accessToken, string $pixelId, array $events, bool $debugMode = false): mixed
    {
        Api::init(null, null, $accessToken, false);
        $api = Api::instance();
        if ($debugMode) {
            $api->setLogger(new CurlLogger());
        }

        $request = new EventRequest($pixelId);
        $request->setEvents($events);

        return $request->execute();
    }

    private function _getHelper(): Hirale_MetaConversions_Helper_Data
    {
        if ($this->_helper === null) {
            $helper = Mage::helper('metaconversions');
            if (!$helper instanceof Hirale_MetaConversions_Helper_Data) {
                throw new RuntimeException('Hirale MetaConversions helper is unavailable.');
            }
            $this->_helper = $helper;
        }

        return $this->_helper;
    }
}
