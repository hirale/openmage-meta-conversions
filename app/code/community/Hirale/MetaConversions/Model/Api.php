<?php

declare(strict_types=1);

use FacebookAds\Api;
use FacebookAds\Http\Exception\AuthorizationException;
use FacebookAds\Http\Exception\ClientException;
use FacebookAds\Http\Exception\EmptyResponseException;
use FacebookAds\Http\Exception\PermissionException;
use FacebookAds\Http\Exception\RequestException;
use FacebookAds\Http\Exception\ServerException;
use FacebookAds\Http\Exception\ThrottleException;
use FacebookAds\Logger\CurlLogger;
use FacebookAds\Object\ServerSide\CustomData;
use FacebookAds\Object\ServerSide\Event;
use FacebookAds\Object\ServerSide\EventRequest;
use FacebookAds\Object\ServerSide\UserData;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Handler for queued Meta Conversions API uploads.
 *
 * Registered twice on purpose: the #[\Maho\Config\MessageHandler] attribute
 * for Maho's core queue, and <hirale_queue><handlers> in config.xml for
 * hirale/queue on OpenMage. Each backend ignores the other's registration.
 */
class Hirale_MetaConversions_Model_Api
{
    public const LOG_FILE = 'meta_conversions.log';

    private ?Hirale_MetaConversions_Helper_Data $_helper = null;

    #[\Maho\Config\MessageHandler]
    public function __invoke(Hirale_MetaConversions_Message_CapiEventMessage $message): void
    {
        $helper = $this->_getHelper();
        $accessToken = $helper->getAccessToken($message->storeId);
        $pixelId = $helper->getPixelId($message->storeId);

        if ($accessToken === null || $pixelId === null) {
            // Forced: a missing credential is a configuration fault, not
            // debug noise. Gated on debugMode it went unlogged on exactly the
            // stores that needed to see it — production, where the platform
            // log switch is off — and the events vanished without a trace.
            Mage::log(
                sprintf(
                    'Dropped %d CAPI event(s): access token or pixel id not configured for store %d.',
                    count($message->events),
                    $message->storeId,
                ),
                null,
                self::LOG_FILE,
                true,
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
        } catch (RequestException $e) {
            if (!$this->_isPermanent($e)) {
                throw $e;
            }
            // A replay of this request reproduces the same rejection, so fail
            // the message permanently instead of burning the queue's retry
            // schedule on the whole backlog.
            Mage::log(
                sprintf(
                    'Dropped %d CAPI event(s) for store %d, Meta rejected the request: %s',
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
     * Whether a Graph API rejection can never succeed on replay.
     *
     * Classification is by exception class first: the SDK picks the subclass
     * from Meta's own error code, and Meta answers HTTP 400 for most Graph
     * errors — rate limits included — so the status alone would strand
     * retryable failures. The status only decides the leftovers the SDK
     * leaves as a plain RequestException.
     */
    protected function _isPermanent(RequestException $e): bool
    {
        if ($e instanceof ThrottleException || $e instanceof ServerException || $e instanceof EmptyResponseException) {
            return false;
        }

        // Bad or expired token and invalid parameters (codes 100/190) land on
        // AuthorizationException, a missing capability on PermissionException,
        // a duplicate post (code 506) on ClientException.
        if ($e instanceof AuthorizationException || $e instanceof PermissionException || $e instanceof ClientException) {
            return true;
        }

        $status = (int) $e->getHttpStatusCode();

        return $status >= 400 && $status < 500;
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
