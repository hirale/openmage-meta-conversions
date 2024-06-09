<?php
class Hirale_MetaConversions_Model_Observer
{
    protected $helper;
    protected $gaHelper;
    protected $queue;
    public function __construct(
    ) {
        $this->helper = Mage::helper('metaconversions');
        $this->gaHelper = Mage::helper('googleanalytics');
        $this->queue = Mage::getModel('hirale_queue/task');
    }


    /**
     * Add a task to the queue for processing by the Hirale_MetaConversions_Model_Api class.
     *
     * @param array $event The name of the event to be processed.
     * @param array $userData An array containing user data to be associated with the event.
     * @param array|null $customData An optional array containing custom data to be associated with the event.
     */
    protected function addToQueue($event, $userData, $customData = null)
    {
        try {
            $this->queue->addTask(
                'Hirale_MetaConversions_Model_Api',
                compact('event', 'userData', 'customData')
            );
        } catch (Exception $e) {
            Mage::logException($e);
        }
    }

    /**
     * Observe the "sales_quote_item_save_after" event and add the product to the queue for processing.
     *
     * @param Varien_Event_Observer $observer The event observer instance.
     */

    public function addToCart(Varien_Event_Observer $observer)
    {
        if (!$this->helper->isConversionsEnabled()) {
            return;
        }
        /** @var Mage_Sales_Model_Quote_Item $item */
        $item = $observer->getEvent()->getItem();
        $quote = Mage::getSingleton('checkout/session')->getQuote();
        if ($item->getParentItem()) {
            return;
        }
        if ($item->getQuoteId() != $quote->getId()) {
            return;
        }
        $processedProductsRegistry = Mage::registry('processed_quote_items_for_metaconversions') ?? new ArrayObject();
        if ($processedProductsRegistry->offsetExists($item->getId())) {
            return;
        }
        $processedProductsRegistry[$item->getId()] = true;
        Mage::register('processed_quote_items_for_metaconversions', $processedProductsRegistry, true);

        $addedQty = 0;
        if ($item->isObjectNew()) {
            $addedQty = $item->getQty();
        } elseif ($item->hasDataChanges()) {
            $newQty = $item->getQty();
            $oldQty = $item->getOrigData('qty');
            if ($newQty > $oldQty) {
                $addedQty = $newQty - $oldQty;
            }
        }
        if ($addedQty) {
            $customData = [
                'content_type' => 'product',
                'content_ids' => [$item->getSku()],
                'contents' => [
                    [
                        $item->getSku(),
                        $addedQty,
                        $item->getBasePrice(),
                        $item->getName(),
                    ]
                ],
                'currency' => Mage::app()->getStore()->getBaseCurrencyCode(),
                'value' => $this->helper->formatPrice($item->getBaseRowTotal()),
            ];

            $this->addToQueue([
                'event_time' => time(),
                'event_source_url' => $this->helper->getCurrentUrl(),
                'action_source' => $this->helper->getActionSource(),
                'event_id' => $this->helper->getEventId(),
                'event_name' => 'AddToCart'
            ], $this->helper->prepareUserData(), $customData);
        }
    }

    /**
     * Observe the "wishlist_product_add_after" event and add the product to the queue for processing.
     *
     * @param Varien_Event_Observer $observer The event observer instance.
     */
    public function addToWishlist(Varien_Event_Observer $observer)
    {
        $items = $observer->getEvent()->getItems();
        if (!$this->helper->isConversionsEnabled()) {
            return;
        }
        if (count($items) > 0) {
            $contents = [];
            $contentIds = [];
            $value = 0;
            foreach ($items as $item) {
                $_product = $item->getProduct();
                $_price = $_product->getFinalPrice();
                $contents[] = [
                    $_product->getSku(),
                    1,
                    $this->helper->formatPrice($_price),
                    $_product->getName()
                ];
                $contentIds[] = $_product->getSku();
                $value += $_price;
            }
            $customData = [
                'content_type' => 'product',
                'content_ids' => $contentIds,
                'contents' => $contents,
                'currency' => Mage::app()->getStore()->getBaseCurrencyCode(),
                'value' => $this->helper->formatPrice($value),
            ];
            $this->addToQueue([
                'event_time' => time(),
                'event_source_url' => $this->helper->getCurrentUrl(),
                'action_source' => $this->helper->getActionSource(),
                'event_id' => $this->helper->getEventId(),
                'event_name' => 'AddToWishlist'
            ], $this->helper->prepareUserData(), $customData);
        }
    }

    /**
     * Observe the "customer_register_success" event and add the customer registration to the queue for processing.
     *
     * @param Varien_Event_Observer $observer The event observer instance.
     */
    public function completeRegistration(Varien_Event_Observer $observer)
    {
        $customer = $observer->getEvent()->getCustomer();
        $this->addToQueue([
            'event_time' => time(),
            'event_source_url' => $this->helper->getCurrentUrl(),
            'action_source' => $this->helper->getActionSource(),
            'event_id' => $this->helper->getEventId(),
            'event_name' => 'CompleteRegistration'
        ], $this->helper->prepareUserData($customer));
    }

    /**
     * Observe the "core_app_run_after" event and add various events to the queue for processing based on the current route.
     *
     * @param Varien_Event_Observer $observer The event observer instance.
     */
    public function dispatchRouteEvent(Varien_Event_Observer $observer)
    {
        if (!$this->helper->isConversionsEnabled()) {
            return;
        }
        $currency = Mage::app()->getStore()->getBaseCurrencyCode();
        $request = $observer->getEvent()->getApp()->getRequest();
        $route = $request->getModuleName() . '_' . $request->getControllerName() . '_' . $request->getActionName();
        $eventName = null;
        $customData = null;

        switch ($route) {
            case 'checkout_onepage_index':
                $eventName = 'InitiateCheckout';
                $customData = $this->prepareInitiateCheckoutCustomData($currency);
                break;

            case 'checkout_onepage_success':
                $eventName = 'Purchase';
                $customData = $this->preparePurchaseCustomData($currency);
                break;

            case 'checkout_cart_index':
                $eventName = 'ViewCart';
                $customData = $this->prepareInitiateCheckoutCustomData($currency);
                break;

            case 'catalog_product_view':
                if (Mage::registry('current_product')) {
                    $eventName = 'ViewContent';
                    $customData = $this->prepareViewContentCustomData($currency);
                }
                break;
            case 'catalogsearch_result_index':
                $eventName = 'Search';
                $customData = $this->prepareSearchCustomData($currency, $request->getParam('q'));
                break;
        }
        $userData = $this->helper->prepareUserData();
        $event = [
            'event_time' => time(),
            'event_source_url' => $this->helper->getCurrentUrl(),
            'action_source' => $this->helper->getActionSource()
        ];

        if ($eventName && $customData) {
            $event['event_name'] = $eventName;
            $event['event_id'] = $this->helper->getEventId();
            $this->addToQueue($event, $userData, $customData);
        }
        $event['event_name'] = 'PageView';
        $event['event_id'] = $this->helper->getEventId();
        $this->addToQueue($event, $userData);

    }

    /**
     * Prepare custom data for the "InitiateCheckout" event.
     *
     * @param string $currency The base currency code.
     * @return array The custom data array.
     */
    protected function prepareInitiateCheckoutCustomData($currency)
    {
        $quote = Mage::getSingleton('checkout/session')->getQuote();
        $contents = [];
        $contentIds = [];
        $value = 0;

        foreach ($quote->getAllVisibleItems() as $quoteItem) {
            if ($quoteItem->getParentItem()) {
                continue;
            }

            $contentIds[] = $quoteItem->getSku();
            $contents[] =
                [
                    $quoteItem->getSku(),
                    $quoteItem->getQty(),
                    $quoteItem->getBasePrice(),
                    $quoteItem->getName()
                ];
            $value += $quoteItem->getBasePrice();
        }

        return [
            'content_type' => 'product',
            'content_ids' => $contentIds,
            'contents' => $contents,
            'currency' => $currency,
            'value' => $this->helper->formatPrice($value),
            'num_items' => $quote->getItemsSummaryQty(),
        ];
    }

    /**
     * Prepare custom data for the "Purchase" event.
     *
     * @param string $currency The base currency code.
     * @return array The custom data array.
     */
    protected function preparePurchaseCustomData($currency)
    {
        $order = Mage::getSingleton('checkout/session')->getLastRealOrder();
        $contentIds = [];
        $contents = [];

        foreach ($order->getAllVisibleItems() as $orderItem) {
            if ($orderItem->getParentItem()) {
                continue;
            }

            $contentIds[] = $orderItem->getSku();
            $contents[] =
                [
                    $orderItem->getSku(),
                    $orderItem->getQtyOrdered(),
                    $orderItem->getBasePrice(),
                    $orderItem->getName()
                ];
        }

        return [
            'content_type' => 'product',
            'content_ids' => $contentIds,
            'currency' => $currency,
            'value' => $this->helper->formatPrice($order->getBaseGrandTotal()),
            'num_items' => $order->getTotalQtyOrdered(),
            'contents' => $contents,
            'order_id' => (string) $order->getIncrementId(),
        ];
    }

    /**
     * Prepare custom data for the "ViewContent" event.
     *
     * @param string $currency The base currency code.
     * @return array The custom data array.
     */
    protected function prepareViewContentCustomData($currency)
    {
        $product = Mage::registry('current_product');

        return [
            'currency' => $currency,
            'content_type' => 'product',
            'content_ids' => [$product->getSku()],
            'content_category' => $this->gaHelper->getLastCategoryName($product) ?? '',
            'contents' => [
                [
                    $product->getSku(),
                    1,
                    $this->helper->formatPrice($product->getFinalPrice()),
                    $product->getName()
                ]
            ],
        ];
    }

    /**
     * Prepare custom data for the "Search" event.
     *
     * @param string $currency The base currency code.
     * @param string $q The search query string.
     * @return array The custom data array.
     */
    protected function prepareSearchCustomData($currency, $q)
    {
        $toolbarBlock = Mage::app()->getLayout()->getBlock('product_list_toolbar');
        $listBlock = Mage::app()->getLayout()->getBlock('search_result_list');
        $productCollection = $listBlock->getLoadedProductCollection();
        $pageSize = $toolbarBlock->getLimit();
        $currentPage = $toolbarBlock->getCurrentPage();

        if ($pageSize !== 'all') {
            $productCollection->setPageSize($pageSize)->setCurPage($currentPage);
        }

        $contents = [];
        $contentIds = [];
        $value = 0;
        foreach ($productCollection as $product) {
            $contents[] = [
                $product->getSku(),
                1,
                $this->helper->formatPrice($product->getFinalPrice()),
                $product->getName()
            ];
            $contentIds[] = $product->getSku();
            $value += $product->getFinalPrice();
        }
        return [
            'currency' => $currency,
            'content_type' => 'product',
            'content_ids' => $contentIds,
            'contents' => $contents,
            'search_string' => $q
        ];
    }

}