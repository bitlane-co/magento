<?php
/**
 * Bitlane Search for Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to a commercial license from NMC, in the file
 * LICENSE.md that comes with it. Use, copy, modification or distribution of
 * this file or its parts without NMC's written permission is not allowed.
 *
 * @author    Bitlane <https://bitlane.co>
 * @copyright Since 2026 NMC
 * @license   Commercial license, see LICENSE.md
 */

declare(strict_types=1);

namespace Bitlane\Search\Model\Storefront;

use Bitlane\Search\Model\Connection\BitlaneApi;
use Bitlane\Search\Model\Connection\ConnectionStore;
use Bitlane\Search\Model\Queue\HandlerInterface;
use Bitlane\Search\Model\Queue\Jobs;
use Bitlane\Search\Model\Site\Settings;
use Bitlane\Search\Model\Site\Site;
use Bitlane\Search\Model\Support\BitlaneError;
use Magento\Framework\App\Area;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State as AppState;
use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Header;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The store's events, sent to Bitlane from the server (docs/magento-plan.md MG17, docs/conversions-plan.md C6, C11).
 *
 * POST /api/integrations/:id/events, in the language of the store view
 * whose index holds the documents. Ad blockers can't drop them and shoppers
 * can't fake them, unlike Bitlane.track(). The widget's session (its
 * bitlane_session cookie, written only with tracking on; headless
 * storefronts send X-Bitlane-Session) is copied onto the quote
 * (bitlane_quote), because payment callbacks place and invoice orders
 * without the shopper's cookies. Each event is a job (type event) whose
 * arguments carry its id, so a retry sends the same event:
 * - an addition to a quote, from the storefront, REST or GraphQL alike;
 * - a purchase when the order's first invoice is paid (offline payments
 *   count when invoiced), with the whole order, once (bitlane_order);
 * - a refund per credit memo of a reported order.
 */
class Events implements HandlerInterface
{
    public const JOB = 'event';

    /**
     * @param BitlaneApi $api
     * @param ConnectionStore $connection
     * @param Site $site
     * @param Settings $settings
     * @param Jobs $jobs
     * @param ResourceConnection $resource
     * @param StoreManagerInterface $storeManager
     * @param CookieManagerInterface $cookies
     * @param HttpRequest $request
     * @param Header $header
     * @param AppState $appState
     * @param OrderRepositoryInterface $orders
     * @param CreditmemoRepositoryInterface $creditmemos
     * @param IdentityGeneratorInterface $ids
     */
    public function __construct(
        private readonly BitlaneApi $api,
        private readonly ConnectionStore $connection,
        private readonly Site $site,
        private readonly Settings $settings,
        private readonly Jobs $jobs,
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly CookieManagerInterface $cookies,
        private readonly HttpRequest $request,
        private readonly Header $header,
        private readonly AppState $appState,
        private readonly OrderRepositoryInterface $orders,
        private readonly CreditmemoRepositoryInterface $creditmemos,
        private readonly IdentityGeneratorInterface $ids
    ) {
    }

    /**
     * Products added to a quote by a shopper: the additions queued.
     *
     * @param list<QuoteItem> $items sales_quote_product_add_after's, a configurable's child among them
     * @return void
     */
    public function added(array $items): void
    {
        if ($this->inAdmin()) {
            return;
        }
        $events = [];
        $storeViewId = 0;
        foreach ($items as $item) {
            // What this addition added (Quote\Item::addQty()).
            $quantity = $item instanceof QuoteItem ? (int) $item->getData('qty_to_add') : 0;
            if ($quantity <= 0 || $item->getParentItem()) {
                continue;
            }
            $storeViewId = (int) $item->getStoreId();
            $child = $item->getChildren()[0] ?? null;
            $events[] = [
                'id' => $this->uuid(),
                'type' => 'add_to_cart',
                'session_id' => $this->session(),
                'at' => time(),
                'document_id' => $this->documentId(
                    $storeViewId,
                    (int) $item->getProductId(),
                    $child ? (int) $child->getProductId() : 0
                ),
                'product_id' => (string) $item->getProductId(),
                'quantity' => min($quantity, 100000),
                'device' => $this->device((string) $this->header->getHttpUserAgent()),
            ];
        }
        $storeId = $this->storeId($storeViewId);
        $language = $this->language($storeViewId);
        if ($events && $storeId && $language !== '') {
            $this->jobs->push($storeId, self::JOB, ['cart', $language, $events]);
        }
    }

    /**
     * A quote saved during a shopper's request: the widget's session onto it.
     *
     * @param Quote $quote
     * @return void
     */
    public function quoteSaved(Quote $quote): void
    {
        $session = $this->inAdmin() ? '' : $this->session();
        if ($session === '' || !$quote->getId() || !$this->storeId((int) $quote->getStoreId())) {
            return;
        }
        $this->db()->insertOnDuplicate(
            $this->resource->getTableName('bitlane_quote'),
            ['quote_id' => (int) $quote->getId(), 'session_id' => $session, 'updated_at' => gmdate('Y-m-d H:i:s')],
            ['session_id', 'updated_at']
        );
    }

    /**
     * An invoice paid: its order's purchase queued, the first time.
     *
     * @param Order $order
     * @return void
     */
    public function invoicePaid(Order $order): void
    {
        $storeId = $this->storeId((int) $order->getStoreId());
        if (!$storeId || !$order->getId() || $this->reported((int) $order->getId())) {
            return;
        }
        $this->jobs->push(
            $storeId,
            self::JOB,
            ['purchase', (int) $order->getId(), $this->uuid()],
            0,
            'purchase:' . $order->getId()
        );
    }

    /**
     * A new credit memo of a reported order, or one whose purchase waits to be sent: its refund queued.
     *
     * It runs after the purchase, queued before it.
     *
     * @param Creditmemo $creditmemo
     * @return void
     */
    public function refunded(Creditmemo $creditmemo): void
    {
        $order = $creditmemo->getOrder();
        $storeId = $this->storeId((int) $order->getStoreId());
        if (!$storeId || !$creditmemo->getId() || $creditmemo->getOrigData('entity_id') !== null
            || (!$this->reported((int) $order->getId()) && !$this->jobs->has($storeId, 'purchase:' . $order->getId()))
        ) {
            return;
        }
        $this->jobs->push(
            $storeId,
            self::JOB,
            ['refund', (int) $creditmemo->getId(), $this->uuid()],
            0,
            'refund:' . $creditmemo->getId()
        );
    }

    /**
     * An event job: additions as they were; a purchase or a refund as the order and credit memo are now.
     *
     * @param int $storeId
     * @param array<mixed> $args cart, language, events; purchase, order, event id; refund, credit memo, event id
     * @return void
     */
    public function run(int $storeId, array $args): void
    {
        match ((string) ($args[0] ?? '')) {
            'cart' => $this->send($storeId, (string) ($args[1] ?? ''), array_values((array) ($args[2] ?? []))),
            'purchase' => $this->sendPurchase($storeId, (int) ($args[1] ?? 0), (string) ($args[2] ?? $this->uuid())),
            'refund' => $this->sendRefund($storeId, (int) ($args[1] ?? 0), (string) ($args[2] ?? $this->uuid())),
            default => null,
        };
    }

    /**
     * Sessions of quotes untouched for 90 days go: their orders are long placed, or never will be.
     *
     * @return void
     */
    public function prune(): void
    {
        $this->db()->delete(
            $this->resource->getTableName('bitlane_quote'),
            ['updated_at < ?' => gmdate('Y-m-d H:i:s', time() - 90 * 86400)]
        );
    }

    /**
     * A product's document id, as the store's setup makes them.
     *
     * A configurable's variant per variation, else the product's own.
     *
     * @param int $storeViewId
     * @param int $productId
     * @param int $childId a configurable's chosen child; 0 for none
     * @return string
     */
    public function documentId(int $storeViewId, int $productId, int $childId): string
    {
        $store = $this->site->store($this->storeId($storeViewId));
        if ($childId && $store && $this->settings->get($store)['product_documents'] === Settings::PER_VARIATION) {
            return $productId . '-' . $childId;
        }

        return (string) $productId;
    }

    /**
     * The widget's session, from its cookie or a headless storefront's header; empty without one.
     *
     * @return string
     */
    public function session(): string
    {
        $session = (string) ($this->cookies->getCookie('bitlane_session')
            ?: $this->request->getHeader('X-Bitlane-Session'));

        return preg_match('/^[0-9a-f-]{36}$/', $session) ? $session : '';
    }

    /**
     * The shopper's device, from the user agent.
     *
     * @param string $userAgent
     * @return string
     */
    public function device(string $userAgent): string
    {
        if (preg_match('/iPad|Tablet|Android(?!.*Mobile)/i', $userAgent)) {
            return 'tablet';
        }

        return preg_match('/Mobile|iPhone|iPod|Android/i', $userAgent) ? 'mobile' : 'desktop';
    }

    /**
     * The purchase: the order as it is now, unless it was sent already.
     *
     * @param int $storeId
     * @param int $orderId
     * @param string $eventId
     * @return void
     */
    private function sendPurchase(int $storeId, int $orderId, string $eventId): void
    {
        $order = $this->orders->get($orderId);
        if (!$order instanceof Order || $this->reported($orderId)) {
            return;
        }
        $items = [];
        foreach ($order->getAllVisibleItems() as $item) {
            if ((float) $item->getQtyOrdered() > 0) {
                $items[] = $this->item($order, $item, (int) $item->getQtyOrdered(), (float) $item->getPriceInclTax());
            }
        }
        $this->send($storeId, $this->language((int) $order->getStoreId()), [[
            'id' => $eventId,
            'type' => 'purchase',
            'session_id' => $this->quoteSession((int) $order->getQuoteId()),
            'at' => (int) strtotime($order->getCreatedAt() . ' UTC'),
            'purchase' => [
                'order_id' => (string) $order->getIncrementId(),
                'currency' => (string) $order->getOrderCurrencyCode(),
                'value' => round((float) $order->getGrandTotal(), 6),
                // Bitlane takes 200.
                'items' => array_slice($items, 0, 200),
            ],
        ]]);
        $this->db()->insertOnDuplicate(
            $this->resource->getTableName('bitlane_order'),
            ['order_id' => $orderId, 'purchase_at' => gmdate('Y-m-d H:i:s')],
            ['purchase_at']
        );
    }

    /**
     * The refund: a credit memo's products and amount.
     *
     * @param int $storeId
     * @param int $creditmemoId
     * @param string $eventId
     * @return void
     */
    private function sendRefund(int $storeId, int $creditmemoId, string $eventId): void
    {
        $creditmemo = $this->creditmemos->get($creditmemoId);
        if (!$creditmemo instanceof Creditmemo) {
            return;
        }
        $order = $creditmemo->getOrder();
        $items = [];
        foreach ($creditmemo->getAllItems() as $line) {
            $orderItem = $line->getOrderItem();
            if ($orderItem->getParentItem() || (float) $line->getQty() <= 0) {
                continue;
            }
            $items[] = $this->item($order, $orderItem, (int) $line->getQty(), (float) $line->getPriceInclTax());
        }
        $this->send($storeId, $this->language((int) $order->getStoreId()), [[
            'id' => $eventId,
            'type' => 'refund',
            'session_id' => $this->quoteSession((int) $order->getQuoteId()),
            'at' => (int) strtotime($creditmemo->getCreatedAt() . ' UTC'),
            'refund' => [
                'order_id' => (string) $order->getIncrementId(),
                'refund_id' => (string) $creditmemo->getIncrementId(),
                'currency' => (string) $order->getOrderCurrencyCode(),
                'value' => round((float) $creditmemo->getGrandTotal(), 6),
                'items' => array_slice($items, 0, 200),
            ],
        ]]);
    }

    /**
     * An order line as an event's item: its document, its product, how many, the unit price with tax.
     *
     * @param Order $order
     * @param OrderItem $item
     * @param int $quantity
     * @param float $price
     * @return array{document_id: string, product_id: string, quantity: int, price: float}
     */
    private function item(Order $order, OrderItem $item, int $quantity, float $price): array
    {
        $child = $item->getChildrenItems()[0] ?? null;

        return [
            'document_id' => $this->documentId(
                (int) $order->getStoreId(),
                (int) $item->getProductId(),
                $child ? (int) $child->getProductId() : 0
            ),
            // A variant's configurable: it matches a click on another variant.
            'product_id' => (string) $item->getProductId(),
            'quantity' => min($quantity, 100000),
            'price' => round($price, 6),
        ];
    }

    /**
     * Sends events of the store in a language.
     *
     * @param int $storeId
     * @param string $language
     * @param list<array<string,mixed>> $events
     * @return void
     */
    private function send(int $storeId, string $language, array $events): void
    {
        $id = $this->connection->integrationId($storeId);
        if (!$id) {
            throw new BitlaneError('not_connected', (string) __('This store isn’t connected to Bitlane.'), 409);
        }
        if ($language === '' || !$events) {
            return;
        }
        $this->api->request(
            'POST',
            '/api/integrations/' . rawurlencode($id) . '/events',
            ['language' => $language, 'events' => $events]
        );
    }

    /**
     * The store (group) of a store view, when it's connected; 0 otherwise.
     *
     * @param int $storeViewId
     * @return int
     */
    private function storeId(int $storeViewId): int
    {
        if (!$storeViewId) {
            return 0;
        }
        $storeId = (int) $this->storeManager->getStore($storeViewId)->getStoreGroupId();

        return $this->connection->integrationId($storeId) !== null ? $storeId : 0;
    }

    /**
     * A store view's language as its store's setup sent it ("it-IT"); empty when the setup doesn't have it.
     *
     * Bitlane would refuse its events.
     *
     * @param int $storeViewId
     * @return string
     */
    private function language(int $storeViewId): string
    {
        $store = $this->site->store($this->storeId($storeViewId));
        if (!$store) {
            return '';
        }
        $code = $this->site->languageCode($storeViewId);
        foreach ((array) $this->settings->get($store)['languages'] as $language) {
            if (strtolower((string) $language) === strtolower($code)) {
                return (string) $language;
            }
        }

        return '';
    }

    /**
     * A quote's session; empty without one.
     *
     * @param int $quoteId
     * @return string
     */
    private function quoteSession(int $quoteId): string
    {
        $db = $this->db();

        return (string) $db->fetchOne(
            $db->select()->from($this->resource->getTableName('bitlane_quote'), 'session_id')
                ->where('quote_id = ?', $quoteId)
        );
    }

    /**
     * Whether an order's purchase was sent.
     *
     * @param int $orderId
     * @return bool
     */
    private function reported(int $orderId): bool
    {
        $db = $this->db();

        return (bool) $db->fetchOne(
            $db->select()->from($this->resource->getTableName('bitlane_order'), 'order_id')
                ->where('order_id = ?', $orderId)
        );
    }

    /**
     * Whether the request is the admin's (its orders and quotes aren't a shopper's).
     *
     * @return bool
     */
    private function inAdmin(): bool
    {
        try {
            return $this->appState->getAreaCode() === Area::AREA_ADMINHTML;
        } catch (LocalizedException) {
            return false;
        }
    }

    /**
     * The database connection.
     *
     * @return AdapterInterface
     */
    private function db(): AdapterInterface
    {
        return $this->resource->getConnection();
    }

    /**
     * A new event's id (a UUID).
     *
     * @return string
     */
    private function uuid(): string
    {
        return $this->ids->generateId();
    }
}
