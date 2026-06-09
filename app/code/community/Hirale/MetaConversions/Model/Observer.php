<?php

declare(strict_types=1);

use Hirale\Queue\Bus;
use Jaybizzle\CrawlerDetect\CrawlerDetect;

class Hirale_MetaConversions_Model_Observer
{
    protected ?Hirale_MetaConversions_Helper_Data $helper = null;
    protected ?object $queue = null;
    protected ?CrawlerDetect $CrawlerDetect = null;

    public function __construct()
    {
        $helper = Mage::helper('metaconversions');
        if (!$helper instanceof Hirale_MetaConversions_Helper_Data) {
            throw new RuntimeException('Hirale MetaConversions helper is unavailable.');
        }
        $this->helper = $helper;
    }

    public function addToCart(Varien_Event_Observer $observer)
    {
        /** @var Mage_Sales_Model_Quote_Item $item */
        $item = $observer->getEvent()->getItem();
        $quote = Mage::getSingleton('checkout/session')->getQuote();
        $storeId = $this->resolveStoreId($item->getStoreId() ?: $quote->getStoreId());
        if (!$this->canSend($storeId)) {
            return;
        }
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
                    $this->buildContentRow($item->getSku(), $addedQty, $item->getBasePrice(), $item->getName()),
                ],
                'currency' => Mage::app()->getStore($storeId)->getBaseCurrencyCode(),
                // Value must reconcile with the contents above: only the units
                // just added, not the whole line (getBaseRowTotal would triple
                // the reported value when an existing line's qty is increased).
                'value' => $this->helper->formatPrice($addedQty * $item->getBasePrice()),
            ];

            $this->addToQueue(
                $this->buildEvent('AddToCart'),
                $this->helper->prepareUserData(),
                $customData,
                $storeId,
            );
        }
    }

    public function addToWishlist(Varien_Event_Observer $observer)
    {
        $items = $observer->getEvent()->getItems();
        if (!$items || count($items) === 0) {
            return;
        }
        $firstItem = is_array($items) ? reset($items) : $items[0];
        // getStoreId() is a Varien magic getter on Mage_Wishlist_Model_Item, so
        // method_exists() returns false for it — call it directly (it yields
        // null on a non-Varien item, which resolveStoreId folds to current store).
        $storeId = $this->resolveStoreId(is_object($firstItem) ? $firstItem->getStoreId() : null);
        if (!$this->canSend($storeId)) {
            return;
        }

        $contents = [];
        $contentIds = [];
        $value = 0;
        foreach ($items as $item) {
            $_product = $item->getProduct();
            $_price = $_product->getFinalPrice();
            // getQty() is a Varien magic getter (method_exists() is false for it);
            // call it directly and default a missing/zero qty to 1.
            $_qty = $item->getQty() ?: 1;
            $contents[] = $this->buildContentRow($_product->getSku(), $_qty, $_price, $_product->getName());
            $contentIds[] = $_product->getSku();
            $value += $_price * $_qty;
        }
        $customData = [
            'content_type' => 'product',
            'content_ids' => $contentIds,
            'contents' => $contents,
            'currency' => Mage::app()->getStore($storeId)->getBaseCurrencyCode(),
            'value' => $this->helper->formatPrice($value),
        ];
        $this->addToQueue(
            $this->buildEvent('AddToWishlist'),
            $this->helper->prepareUserData(),
            $customData,
            $storeId,
        );
    }

    public function completeRegistration(Varien_Event_Observer $observer)
    {
        $customer = $observer->getEvent()->getCustomer();
        $storeId = $this->resolveStoreId($customer ? $customer->getStoreId() : null);
        if (!$this->canSend($storeId)) {
            return;
        }
        $this->addToQueue(
            $this->buildEvent('CompleteRegistration'),
            $this->helper->prepareUserData($customer),
            null,
            $storeId,
        );
    }

    public function dispatchRouteEvent(Varien_Event_Observer $observer)
    {
        $request = $observer->getEvent()->getApp()->getRequest();
        $route = $request->getModuleName() . '_' . $request->getControllerName() . '_' . $request->getActionName();

        if ($route === 'checkout_onepage_success') {
            $order = Mage::getSingleton('checkout/session')->getLastRealOrder();
            $storeId = $this->resolveStoreId($order ? $order->getStoreId() : null);
        } else {
            $storeId = $this->resolveStoreId();
        }

        if (!$this->canSend($storeId)) {
            return;
        }

        $currency = Mage::app()->getStore($storeId)->getBaseCurrencyCode();
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

        if ($eventName && $customData) {
            $this->addToQueue(
                $this->buildEvent($eventName),
                $userData,
                $customData,
                $storeId,
            );
        }

        $response = $observer->getEvent()->getApp()->getResponse();
        $body = substr($response->getBody(), 0, 100);
        $statusCode = $response->getHttpResponseCode();
        if (strpos($body, '<!DOCTYPE html') !== false && $statusCode == 200) {
            $this->addToQueue(
                $this->buildEvent('PageView'),
                $userData,
                null,
                $storeId,
            );
        }
    }

    /**
     * Build the CAPI event envelope.
     *
     * @return array<string, mixed>
     */
    protected function buildEvent(string $eventName): array
    {
        return [
            'event_time' => time(),
            'event_source_url' => $this->helper->getCurrentUrl(),
            'action_source' => $this->helper->getActionSource(),
            'event_id' => $this->helper->getEventId($eventName),
            'event_name' => $eventName,
        ];
    }

    /**
     * Resolve the storefront store id, defaulting to the current store when
     * the candidate is missing or non-positive.
     */
    protected function resolveStoreId($candidate = null): int
    {
        if ($candidate !== null && $candidate !== '' && (int) $candidate > 0) {
            return (int) $candidate;
        }
        return (int) Mage::app()->getStore()->getId();
    }

    protected function isBot(): bool
    {
        return $this->getCrawlerDetect()->isCrawler(Mage::helper('core/http')->getHttpUserAgent());
    }

    protected function canSend(?int $storeId = null): bool
    {
        return $this->helper->isConversionsEnabled($storeId) && !$this->isBot();
    }

    /**
     * Enqueue a single CAPI event onto the Hirale queue. The store id is
     * carried as `_store_id` in the payload so the worker resolves
     * access_token / pixel_id against the originating store; both
     * `_store_id` and `_debug_mode` are stripped before forwarding to
     * Meta.
     *
     * @param array<string, mixed> $event
     * @param array<string, mixed> $userData
     * @param array<string, mixed>|null $customData
     */
    protected function addToQueue(array $event, array $userData, ?array $customData = null, ?int $storeId = null): void
    {
        try {
            $storeId = $this->resolveStoreId($storeId);
            Bus::dispatch(new Hirale_MetaConversions_Message_CapiEventMessage(
                event: $event,
                userData: $userData,
                customData: $customData,
                storeId: (int) $storeId,
                debugMode: $this->helper->isDebugMode($storeId),
            ));
        } catch (Exception $e) {
            Mage::logException($e);
        }
    }

    protected function getQueue()
    {
        if ($this->queue === null) {
            $queue = Mage::getModel('hirale_queue/queue');
            if (!is_object($queue) || !method_exists($queue, 'enqueue')) {
                throw new RuntimeException('Hirale Queue service is unavailable.');
            }
            $this->queue = $queue;
        }
        return $this->queue;
    }

    protected function getCrawlerDetect()
    {
        if ($this->CrawlerDetect === null) {
            $this->CrawlerDetect = new CrawlerDetect();
        }
        return $this->CrawlerDetect;
    }

    /**
     * @return array<string, mixed>
     */
    protected function prepareInitiateCheckoutCustomData($currency): array
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
            $contents[] = $this->buildContentRow($quoteItem->getSku(), $quoteItem->getQty(), $quoteItem->getBasePrice(), $quoteItem->getName());
            $value += $quoteItem->getBasePrice() * $quoteItem->getQty();
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
     * @return array<string, mixed>
     */
    protected function preparePurchaseCustomData($currency): array
    {
        $order = Mage::getSingleton('checkout/session')->getLastRealOrder();
        $contentIds = [];
        $contents = [];

        foreach ($order->getAllVisibleItems() as $orderItem) {
            if ($orderItem->getParentItem()) {
                continue;
            }
            $contentIds[] = $orderItem->getSku();
            $contents[] = $this->buildContentRow($orderItem->getSku(), $orderItem->getQtyOrdered(), $orderItem->getBasePrice(), $orderItem->getName());
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
     * @return array<string, mixed>
     */
    protected function prepareViewContentCustomData($currency): array
    {
        $product = Mage::registry('current_product');

        return [
            'currency' => $currency,
            'content_type' => 'product',
            'content_ids' => [$product->getSku()],
            'content_category' => $this->resolveCategoryName(),
            'contents' => [
                $this->buildContentRow($product->getSku(), 1, $product->getFinalPrice(), $product->getName()),
            ],
        ];
    }

    /**
     * Resolve the category name for ViewContent's content_category from the
     * category the product is currently being viewed under. Returns an empty
     * string when the product is viewed outside any category context. This
     * intentionally reads only the `current_category` registry so the module
     * carries no dependency on Mage_GoogleAnalytics and adds no extra query.
     */
    protected function resolveCategoryName(): string
    {
        $category = Mage::registry('current_category');
        if (is_object($category) && method_exists($category, 'getName')) {
            return (string) $category->getName();
        }

        return '';
    }

    /**
     * Build a single Meta `contents` tuple [product_id, quantity, item_price,
     * title]. Centralised so every event produces the same shape with a
     * consistent 2-decimal item price (the worker maps it via
     * Hirale_MetaConversions_Helper_Data::prepareContent).
     *
     * sku/name are cast rather than type-hinted: Magento item getters can
     * return null for edge entities (deleted-product references, custom quote
     * items), and a TypeError here would escape the observer's safety net
     * (addToQueue catches Exception, not Error) and break the storefront page.
     *
     * @param string|null $sku
     * @param int|float|string $qty
     * @param int|float|string $price
     * @param string|null $name
     * @return array{0:string,1:int|float|string,2:float,3:string}
     */
    protected function buildContentRow($sku, $qty, $price, $name): array
    {
        return [(string) $sku, $qty, $this->helper->formatPrice($price), (string) $name];
    }

    /**
     * @return array<string, mixed>
     */
    protected function prepareSearchCustomData($currency, $q): array
    {
        $contents = [];
        $contentIds = [];

        // The search result list block has already loaded and paginated its
        // collection during page render, so iterate it directly rather than
        // re-applying the toolbar page size (which would be a no-op or a
        // redundant reload).
        $listBlock = Mage::app()->getLayout()->getBlock('search_result_list');
        if ($listBlock) {
            foreach ($listBlock->getLoadedProductCollection() as $product) {
                $contents[] = $this->buildContentRow($product->getSku(), 1, $product->getFinalPrice(), $product->getName());
                $contentIds[] = $product->getSku();
            }
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
