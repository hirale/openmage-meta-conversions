<?php

declare(strict_types=1);

use FacebookAds\Api;
use FacebookAds\Logger\CurlLogger;
use FacebookAds\Object\ServerSide\CustomData;
use FacebookAds\Object\ServerSide\Event;
use FacebookAds\Object\ServerSide\EventRequest;
use FacebookAds\Object\ServerSide\UserData;

class Hirale_MetaConversions_Model_Api implements Hirale_Queue_Model_TaskHandlerInterface
{
    public const META_STORE_ID = '_store_id';
    public const META_DEBUG_MODE = '_debug_mode';

    private ?Hirale_MetaConversions_Helper_Data $_helper = null;

    /**
     * @param array<string, mixed> $task
     */
    public function handle(array $task): void
    {
        $payload = is_array($task['data'] ?? null) ? $task['data'] : [];
        $storeId = isset($payload[self::META_STORE_ID]) ? (int) $payload[self::META_STORE_ID] : null;
        $debugMode = !empty($payload[self::META_DEBUG_MODE]);

        $eventData = is_array($payload['event'] ?? null) ? $payload['event'] : [];
        $userData = is_array($payload['userData'] ?? null) ? $payload['userData'] : [];
        $customData = isset($payload['customData']) && is_array($payload['customData']) ? $payload['customData'] : null;

        $helper = $this->_getHelper();
        $accessToken = $helper->getAccessToken($storeId);
        $pixelId = $helper->getPixelId($storeId);

        if ($accessToken === null || $pixelId === null) {
            return;
        }

        $event = new Event($eventData);
        $event->setUserData(new UserData($userData));

        if ($customData !== null) {
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

        $response = $this->_sendEvent($accessToken, $pixelId, $event);

        if ($debugMode) {
            Mage::log($event);
            Mage::log($response);
        }
    }

    /**
     * Build the CAPI request and execute it. Factored out so unit tests can
     * intercept without hitting graph.facebook.com.
     */
    protected function _sendEvent(string $accessToken, string $pixelId, Event $event): mixed
    {
        Api::init(null, null, $accessToken, false);
        $api = Api::instance();
        $api->setLogger(new CurlLogger());

        $request = new EventRequest($pixelId);
        $request->setEvents([$event]);

        return $request->execute();
    }

    public function setHelper(Hirale_MetaConversions_Helper_Data $helper): self
    {
        $this->_helper = $helper;

        return $this;
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
