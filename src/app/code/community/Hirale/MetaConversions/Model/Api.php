<?php
use FacebookAds\Api;
use FacebookAds\Logger\CurlLogger;
use FacebookAds\Object\ServerSide\Event;
use FacebookAds\Object\ServerSide\CustomData;
use FacebookAds\Object\ServerSide\EventRequest;
use FacebookAds\Object\ServerSide\UserData;

class Hirale_MetaConversions_Model_Api implements Hirale_Queue_Model_TaskHandlerInterface
{
    protected $helper;

    public function __construct(
    ) {
        $this->helper = Mage::helper('metaconversions');
    }

    public function handle($task)
    {
        $event = $task['data']['event'];
        Api::init(null, null, $this->helper->getAccessToken(), false);
        $api = Api::instance();
        $api->setLogger(new CurlLogger());
        $pixelId = $this->helper->getPixelId();
        $event = new Event($event);
        $event->setUserData(new UserData($task['data']['userData']));
        if (isset($task['data']['customData'])) {
            $customData = $task['data']['customData'];
            $contents = [];
            if (isset($customData['contents'])) {
                foreach ($customData['contents'] as $content) {
                    $contents[] = $this->helper->prepareContent($content);
                }
            }
            $customData['contents'] = $contents;
            $event->setCustomData(new CustomData($customData));
        }
        $request = new EventRequest($pixelId);
        $request->setEvents([$event]);
        $response = $request->execute();
        if ($this->helper->isDebugMode()) {
            Mage::log($event);
            Mage::log($response);
        }
    }
}