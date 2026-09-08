<?php

declare(strict_types=1);

use Jaybizzle\CrawlerDetect\CrawlerDetect;

class Hirale_MetaConversions_Model_Observer
{
    /** Checkout-session key holding the increment id whose Purchase was already reported. */
    public const SESSION_REPORTED_PURCHASE = 'meta_reported_purchase_increment_id';

    protected Hirale_MetaConversions_Helper_Data $helper;
    protected ?CrawlerDetect $crawlerDetect = null;
    protected ?bool $isBotResult = null;

    /**
     * Quote item ids already reported during this request.
     * sales_quote_item_save_after fires again for the same item while totals
     * are collected; this per-request singleton keeps the dedup state as an
     * instance property instead of going through the global registry.
     *
     * @var array<string, true>
     */
    protected array $processedQuoteItemIds = [];

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
        $this->guard(fn() => $this->_addToCart($observer));
    }

    protected function _addToCart(Varien_Event_Observer $observer)
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
        $itemId = (string) $item->getId();
        if (isset($this->processedQuoteItemIds[$itemId])) {
            return;
        }
        $this->processedQuoteItemIds[$itemId] = true;

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
                [$this->buildEventEntry('AddToCart', $customData)],
                $this->helper->prepareUserData(),
                $storeId,
            );
        }
    }

    public function addToWishlist(Varien_Event_Observer $observer)
    {
        $this->guard(fn() => $this->_addToWishlist($observer));
    }

    protected function _addToWishlist(Varien_Event_Observer $observer)
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
            [$this->buildEventEntry('AddToWishlist', $customData)],
            $this->helper->prepareUserData(),
            $storeId,
        );
    }

    public function completeRegistration(Varien_Event_Observer $observer)
    {
        $this->guard(fn() => $this->_completeRegistration($observer));
    }

    protected function _completeRegistration(Varien_Event_Observer $observer)
    {
        $customer = $observer->getEvent()->getCustomer();
        $storeId = $this->resolveStoreId($customer ? $customer->getStoreId() : null);
        if (!$this->canSend($storeId)) {
            return;
        }
        $this->addToQueue(
            [$this->buildEventEntry('CompleteRegistration')],
            $this->helper->prepareUserData($customer),
            $storeId,
        );
    }

    public function dispatchRouteEvent(Varien_Event_Observer $observer)
    {
        $this->guard(fn() => $this->_dispatchRouteEvent($observer));
    }

    protected function _dispatchRouteEvent(Varien_Event_Observer $observer)
    {
        $request = $observer->getEvent()->getApp()->getRequest();
        $route = $request->getModuleName() . '_' . $request->getControllerName() . '_' . $request->getActionName();

        $order = null;
        if ($route === 'checkout_onepage_success') {
            $order = Mage::getSingleton('checkout/session')->getLastRealOrder();
            $storeId = $this->resolveStoreId($order ? $order->getStoreId() : null);
        } else {
            $storeId = $this->resolveStoreId();
        }

        if (!$this->canSend($storeId)) {
            return;
        }

        // Hoisted above the route switch on purpose: every event below reads
        // quote or order state that only a rendered page can be trusted to
        // reflect. PageView used to be the only one guarded.
        if (!$this->isHtmlPageResponse($observer->getEvent()->getApp()->getResponse())) {
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
                if ($this->claimPurchaseReport($order)) {
                    $eventName = 'Purchase';
                    $customData = $this->preparePurchaseCustomData($currency);
                }
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
                $q = $request->getParam('q');
                $eventName = 'Search';
                $customData = $this->prepareSearchCustomData($currency, is_string($q) ? $q : '');
                break;
        }

        $events = [];
        if ($eventName && $customData) {
            $events[] = $this->buildEventEntry($eventName, $customData);
        }
        $events[] = $this->buildEventEntry('PageView');

        // prepareUserData (and its customer address lookup) is reached only
        // past the rendered-page guard, so AJAX calls, redirects and error
        // responses never pay for it. All events of the request share one
        // message and therefore one Graph API call.
        $this->addToQueue($events, $this->helper->prepareUserData(), $storeId);
    }

    /**
     * Analytics must never break the flow it observes. Every event entry point
     * runs its body through here, so a payload-building failure is logged and
     * dropped instead of aborting a cart save or a registration.
     */
    protected function guard(callable $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            Mage::logException($e);
        }
    }

    /**
     * Claim the single Purchase report for an order, or refuse when it was
     * already made.
     *
     * The success route outlives its first render: successAction clears
     * lastSuccessQuoteId and redirects on a reload, but last_real_order_id
     * stays on the session (only clearHelperData() drops it, and that runs
     * when the next checkout starts). getLastRealOrder() therefore keeps
     * returning the order. Meta cannot absorb the duplicate either: each
     * dispatch mints its own event_id, and deduplication is keyed on
     * (event_name, event_id).
     *
     * Maho only. OpenMage runs session_write_close() before core_app_run_after
     * dispatches, so the mark never reaches storage there and the claim is a
     * no-op across requests. What actually stops the reload on both platforms
     * is isHtmlPageResponse(): a reload of the success page is a redirect.
     * This claim is the second line of defence, not the first.
     */
    protected function claimPurchaseReport($order): bool
    {
        $incrementId = $order ? (string) $order->getIncrementId() : '';
        if ($incrementId === '') {
            return false;
        }

        $session = Mage::getSingleton('checkout/session');
        if ((string) $session->getData(self::SESSION_REPORTED_PURCHASE) === $incrementId) {
            return false;
        }
        $session->setData(self::SESSION_REPORTED_PURCHASE, $incrementId);

        return true;
    }

    /**
     * Whether the response the visitor received is a rendered storefront page.
     * A redirect, a JSON endpoint or an error page carries no reliable quote
     * or order state: reporting from one duplicates events (a reloaded success
     * page) or invents empty ones (checkout bouncing an empty cart back).
     *
     * The doctype sniff is case-insensitive ("<!DOCTYPE html" and the HTML5-
     * canonical "<!doctype html>" both match) and covers every body segment.
     * It used to read a leading window of the first segment only, which was
     * survivable while it gated PageView alone; now that it gates every route
     * event, a theme prefixing the body with a BOM, a comment or whitespace —
     * or emitting the doctype from a later appendBody() segment — would
     * silently stop all reporting.
     *
     * @param Mage_Core_Controller_Response_Http $response untyped because the
     *        concrete response class differs between OpenMage and Maho.
     */
    protected function isHtmlPageResponse($response): bool
    {
        if ((int) $response->getHttpResponseCode() !== 200) {
            return false;
        }
        $body = $response->getBody(true);
        if (is_array($body)) {
            $body = implode('', $body);
        }

        return stripos((string) $body, '<!doctype html') !== false;
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
     * Wrap one event envelope with its CustomData payload in the shape
     * carried by Hirale_MetaConversions_Message_CapiEventMessage::$events.
     *
     * @param array<string, mixed>|null $customData
     * @return array{event: array<string, mixed>, custom_data: array<string, mixed>|null}
     */
    protected function buildEventEntry(string $eventName, ?array $customData = null): array
    {
        return ['event' => $this->buildEvent($eventName), 'custom_data' => $customData];
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
        // The user agent cannot change within a request, so the (relatively
        // expensive) CrawlerDetect regex runs at most once even when several
        // events fire on the same page.
        if ($this->isBotResult === null) {
            $this->isBotResult = $this->getCrawlerDetect()->isCrawler(Mage::helper('core/http')->getHttpUserAgent());
        }

        return $this->isBotResult;
    }

    protected function canSend(?int $storeId = null): bool
    {
        return $this->helper->isConversionsEnabled($storeId) && !$this->isBot();
    }

    /**
     * Enqueue the CAPI events captured for the current request as one queue
     * message. The store id is carried in the payload so the worker resolves
     * access_token / pixel_id against the originating store.
     *
     * The helper picks the queue backend; with none installed it declines
     * quietly and the storefront request is unaffected.
     *
     * @param list<array{event: array<string, mixed>, custom_data: array<string, mixed>|null}> $events
     * @param array<string, mixed> $userData
     */
    protected function addToQueue(array $events, array $userData, ?int $storeId = null): void
    {
        if ($events === []) {
            return;
        }
        try {
            $storeId = $this->resolveStoreId($storeId);
            $this->helper->enqueueCapiEvents(
                $events,
                $userData,
                $storeId,
                $this->helper->isDebugMode($storeId),
            );
        } catch (Exception $e) {
            Mage::logException($e);
        }
    }

    protected function getCrawlerDetect(): CrawlerDetect
    {
        if ($this->crawlerDetect === null) {
            $this->crawlerDetect = new CrawlerDetect();
        }

        return $this->crawlerDetect;
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
     * Magento item getters return null for edge entities (deleted-product
     * references, custom quote items), so the nullable inputs are normalised
     * to strings here rather than reaching Meta as nulls.
     *
     * @return array{0:string,1:int|float|string,2:float,3:string}
     */
    protected function buildContentRow(?string $sku, int|float|string $qty, int|float|string $price, ?string $name): array
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
