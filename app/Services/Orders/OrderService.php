<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\MilestoneStatus;
use App\Enums\OrderActorType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ProductTerm;
use App\Enums\PromotionReasonCode;
use App\Enums\StockStatus;
use App\Events\Orders\OrderCreated;
use App\Exceptions\Orders\InvalidStatusTransitionException;
use App\Exceptions\Orders\ProductUnavailableException;
use App\Exceptions\Orders\PromoInvalidException;
use App\Http\Resources\Api\V1\Orders\OrderResource;
use App\Models\Address;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderMilestone;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use App\Services\Promotions\PromotionEngine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Orchestration layer for the customer-facing order surface.
 *
 * All money is recomputed server-side from `product_prices`,
 * `country.vat_rate`, and the optional promo evaluation. The client
 * never supplies `amount`, `subtotal`, `discount`, `tax`, or `total`.
 *
 * Idempotency: `OrderService::create()` checks the
 * `(user_id, idempotency_key)` pair against the last 24h of orders and
 * replays the original response when matched (200 vs 201 — surfaced via
 * the `$replayed` flag in the return tuple).
 *
 * See docs/architecture/orders.md § 8.8.
 */
final class OrderService
{
    /**
     * Window in hours during which an `Idempotency-Key` is honoured
     * for replay. Older keys generate a new order.
     */
    private const IDEMPOTENCY_TTL_HOURS = 24;

    public function __construct(
        private readonly PromotionEngine $promotions,
        private readonly OrderStatusTransitionService $transitions,
    ) {}

    /**
     * Create a new order from a validated payload.
     *
     * @param  array<string, mixed>  $payload  StoreOrderRequest::validated()
     * @return array{0: Order, 1: bool} [order, replayed?]
     */
    public function create(User $user, array $payload, ?string $idempotencyKey): array
    {
        // Idempotency replay — runs OUTSIDE the transaction so reading
        // the existing order does not contend with the create path.
        if ($idempotencyKey !== null) {
            $existing = $this->findIdempotencyReplay($user, $idempotencyKey);
            if ($existing !== null) {
                return [$this->eagerLoad($existing), true];
            }
        }

        return [DB::transaction(function () use ($user, $payload, $idempotencyKey): Order {
            $deliveryAddressId = isset($payload['delivery_address_id']) && $payload['delivery_address_id'] !== null
                ? (int) $payload['delivery_address_id']
                : null;
            $deliveryAddress = $deliveryAddressId !== null
                ? $this->loadOwnedAddress($user, $deliveryAddressId)
                : null;

            $billingAddressId = isset($payload['billing_address_id']) && $payload['billing_address_id'] !== null
                ? (int) $payload['billing_address_id']
                : null;
            $billingAddress = $billingAddressId !== null
                ? $this->loadOwnedAddress($user, $billingAddressId)
                : ($deliveryAddress ?? null);

            $deliveryCountry = $deliveryAddress?->country;
            $billingCountry = $billingAddress?->country;
            $addressCurrency = $deliveryCountry !== null ? (string) $deliveryCountry->currency : 'AED';
            $taxRate = $billingCountry !== null ? (float) $billingCountry->vat_rate : 0.0;

            /** @var list<array<string, mixed>> $itemInputs */
            $itemInputs = $payload['items'];
            $lineDrafts = $this->buildLineDrafts($itemInputs, $addressCurrency);

            // Use the product price currency, not the delivery address currency.
            $currency = $lineDrafts[0]['currency'] ?? $addressCurrency;

            $subtotal = 0.0;
            foreach ($lineDrafts as $draft) {
                $subtotal += $draft['line_subtotal'];
            }

            $promotion = null;
            $discount = 0.0;
            $promoCode = isset($payload['promo_code']) ? (string) $payload['promo_code'] : null;
            if ($promoCode !== null && $promoCode !== '') {
                $productIds = array_map(static fn (array $d): int => (int) $d['product_id'], $lineDrafts);
                $categoryIds = array_map(static fn (array $d): int => (int) ($d['category_id'] ?? 0), $lineDrafts);
                $result = $this->promotions->validate(
                    code: $promoCode,
                    user: $user,
                    subtotal: $subtotal,
                    currency: $currency,
                    productIds: $productIds,
                    categoryIds: $categoryIds,
                );

                if (! $result->valid) {
                    throw PromoInvalidException::fromReason(
                        // The DTO guarantees non-null reasonCode on a soft-fail.
                        $result->reasonCode ?? PromotionReasonCode::CodeUnknown,
                        $result->message,
                    );
                }
                $promotion = $result->promotionId;
                $discount = (float) ($result->appliedAmount ?? 0.0);
            }

            $taxBase = max(0.0, $subtotal - $discount);
            $tax = round($taxBase * ($taxRate / 100.0), 2);
            $shipping = 0.0;
            $total = round($subtotal - $discount + $tax + $shipping, 2);

            $order = Order::query()->create([
                'public_id' => Str::uuid()->toString(),
                'user_id' => $user->id,
                'customer_name' => $payload['customer_name'] ?? null,
                'customer_email' => $payload['customer_email'] ?? null,
                'customer_phone' => $payload['customer_phone'] ?? null,
                'delivery_address_id' => $deliveryAddress?->id,
                'delivery_address_line_1' => $payload['delivery_address_line_1'] ?? null,
                'delivery_date' => $payload['delivery_date'] ?? null,
                'delivery_time_slot' => $payload['delivery_time_slot'] ?? null,
                'billing_address_id' => $billingAddress?->id,
                'promotion_id' => $promotion,
                'term' => (string) $payload['term'],
                'payment_method' => (string) $payload['payment_method'],
                'status' => OrderStatus::Pending->value,
                'currency' => $currency,
                'subtotal_amount' => number_format($subtotal, 2, '.', ''),
                'discount_amount' => number_format($discount, 2, '.', ''),
                'tax_amount' => number_format($tax, 2, '.', ''),
                'tax_rate' => number_format($taxRate, 2, '.', ''),
                'shipping_amount' => number_format($shipping, 2, '.', ''),
                'total_amount' => number_format($total, 2, '.', ''),
                'idempotency_key' => $idempotencyKey,
                'idempotency_request_hash' => $idempotencyKey !== null
                    ? hash('sha256', json_encode($this->canonicalisePayload($payload), JSON_THROW_ON_ERROR))
                    : null,
                'notes' => $payload['notes'] ?? null,
                'installation_preferred_time' => $payload['installation_preferred_time'] ?? null,
                'card_last_four' => $payload['card_last_four'] ?? null,
                'card_expiry_month' => $payload['card_expiry_month'] ?? null,
                'card_expiry_year' => $payload['card_expiry_year'] ?? null,
                'card_cvc' => $payload['card_cvc'] ?? null,
            ]);

            // Persist line items with the computed per-line tax / discount
            // shares so the totals reconcile per-line as well as at order
            // level. Pro-rata distribution by line_subtotal share.
            $this->persistLineItems($order, $lineDrafts, $discount, $tax, $subtotal);

            // Initial status-history row: null → pending. Wraps inside
            // the same transaction as the order insert.
            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'from_status' => null,
                'to_status' => OrderStatus::Pending->value,
                'actor_type' => OrderActorType::Customer->value,
                'actor_id' => $user->id,
                'note' => null,
            ]);

            // Phase 11 — fire the OrderCreated domain event after the
            // parent transaction commits, so the listener always sees
            // a canonical persisted order.
            $orderId = (int) $order->id;
            $userId = (int) $user->id;
            DB::afterCommit(static function () use ($orderId, $userId): void {
                event(new OrderCreated($orderId, $userId, $userId));
            });

            return $this->eagerLoad($order->refresh());
        }), false];
    }

    /**
     * Cancel an order. Allowed for customers when status is in
     * `(pending, confirmed)` — the transition service enforces the
     * matrix and throws 409 otherwise.
     */
    public function cancel(Order $order, User $actor, string $reason): Order
    {
        return DB::transaction(function () use ($order, $actor, $reason): Order {
            /** @var Order $locked */
            $locked = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->isCustomerCancellable()) {
                throw InvalidStatusTransitionException::cannotCancel($locked->status);
            }

            $locked->cancellation_reason = $reason;
            $locked->cancelled_by_user_id = $actor->id;
            $locked->save();

            // Delegating the actual state mutation to the transition
            // service keeps history + side-effects in one place.
            $cancelled = $this->transitions->transition(
                order: $locked,
                target: OrderStatus::Cancelled,
                actorType: OrderActorType::Customer,
                actor: $actor,
                note: Str::limit($reason, 480, '…'),
            );

            return $this->eagerLoad($cancelled);
        });
    }

    /**
     * Paginated list of the caller's orders, with the standard eager-
     * loads applied so the {@see OrderResource}
     * never hits N+1.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Order>
     */
    public function listForUser(User $user, array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $query = Order::query()
            ->with([
                'deliveryAddress.country',
                'deliveryAddress.region',
                'promotion',
                'items',
                'user.addresses.country',
                'user.addresses.region',
            ])
            ->withCount('items')
            ->forUser((int) $user->id);

        if (isset($filters['status']) && is_string($filters['status']) && $filters['status'] !== '') {
            $query->withStatus($filters['status']);
        }
        $from = isset($filters['date_from']) && is_string($filters['date_from']) ? $filters['date_from'] : null;
        $to = isset($filters['date_to']) && is_string($filters['date_to']) ? $filters['date_to'] : null;
        $query->placedBetween($from, $to);

        $sort = isset($filters['sort']) && is_string($filters['sort']) ? $filters['sort'] : 'created_at';
        $order = isset($filters['order']) && is_string($filters['order']) ? $filters['order'] : 'desc';
        $query->orderBy($sort, $order)->orderBy('id', 'desc');

        return $query->paginate(perPage: $perPage, page: $page);
    }

    /**
     * Fetch an order owned by $user, eager-loading every relation
     * needed for the detail / tracking responses.
     */
    public function findForUser(Order $order, User $user): Order
    {
        if ($order->user_id !== $user->id) {
            // Defensive — the controller already runs the Policy. This
            // is belt-and-braces for service-layer reuse.
            abort(403);
        }

        return $this->eagerLoad($order);
    }

    /**
     * Build the tracking projection (milestones timeline + next step + window).
     *
     * @return array<string, mixed>
     */
    public function tracking(Order $order): array
    {
        $order = $order->loadMissing(['statusHistory', 'installation', 'milestones']);

        if ($order->milestones->isEmpty()) {
            $this->seedDefaultMilestones($order);
            $order->loadMissing('milestones');
        }

        $milestones = $order->milestones->map(fn (OrderMilestone $m): array => [
            'step_key' => $m->step_key,
            'label' => $m->label,
            'description' => $m->description,
            'status' => $m->status->value,
            'colour' => $m->status->uiColour(),
            'completed_at' => $m->completed_at?->toIso8601String(),
            'scheduled_from_at' => $m->scheduled_from_at?->toIso8601String(),
            'scheduled_to_at' => $m->scheduled_to_at?->toIso8601String(),
            'metadata' => $m->metadata,
        ])->values()->all();

        $current = $order->milestones->first(static fn (OrderMilestone $m): bool => $m->status === MilestoneStatus::Current);

        return [
            'order_id' => $order->id,
            'public_id' => $order->public_id,
            'po_number' => $order->po_number,
            'status' => $order->status->value,
            'tracking_progress' => $order->status->trackingProgress(),
            'current_milestone' => $current ? [
                'label' => $current->label,
                'colour' => $current->status->uiColour(),
            ] : null,
            'next_step' => $this->buildNextStep($order),
            'estimated_delivery_window' => $this->buildDeliveryWindow($order),
            'milestones' => $milestones,
        ];
    }

    /**
     * Seed the default production / delivery milestones for an order
     * if they do not yet exist.
     */
    public function seedDefaultMilestones(Order $order): void
    {
        $steps = [
            [
                'step_key' => 'quotation_approved',
                'label' => __('orders.milestone.quotation_approved'),
                'sort_order' => 1,
            ],
            [
                'step_key' => 'deposit_invoiced',
                'label' => __('orders.milestone.deposit_invoiced'),
                'sort_order' => 2,
            ],
            [
                'step_key' => 'in_production',
                'label' => __('orders.milestone.in_production'),
                'sort_order' => 3,
            ],
            [
                'step_key' => 'phased_delivery',
                'label' => __('orders.milestone.phased_delivery'),
                'sort_order' => 4,
            ],
            [
                'step_key' => 'installation_training',
                'label' => __('orders.milestone.installation_training'),
                'sort_order' => 5,
            ],
        ];

        foreach ($steps as $step) {
            OrderMilestone::query()->firstOrCreate(
                ['order_id' => $order->id, 'step_key' => $step['step_key']],
                [
                    'label' => $step['label'],
                    'status' => MilestoneStatus::Pending,
                    'sort_order' => $step['sort_order'],
                ]
            );
        }
    }

    /**
     * Eager-load all the relations needed by the customer resources so
     * we never N+1. Centralised so list / detail / cancel all share
     * the same load profile.
     */
    public function eagerLoad(Order $order): Order
    {
        return $order->load([
            'items',
            'deliveryAddress.country',
            'deliveryAddress.region',
            'billingAddress.country',
            'billingAddress.region',
            'promotion',
            'statusHistory',
            'installation',
            'payments',
            'milestones',
            'user.addresses.country',
            'user.addresses.region',
        ])->loadCount('items');
    }

    /**
     * Hunt for a previous order with the same (user_id, idempotency_key)
     * inside the TTL window. Older keys are treated as unmatched so the
     * caller creates a fresh order.
     */
    private function findIdempotencyReplay(User $user, string $key): ?Order
    {
        /** @var Order|null $found */
        $found = Order::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', $key)
            ->where('created_at', '>=', now()->subHours(self::IDEMPOTENCY_TTL_HOURS))
            ->latest('id')
            ->first();

        return $found;
    }

    /**
     * Load $userId's address, eager-loading country + region for tax /
     * currency resolution. Throws 404 if not found.
     */
    private function loadOwnedAddress(User $user, int $addressId): Address
    {
        /** @var Address $address */
        $address = Address::query()
            ->with(['country', 'region'])
            ->where('id', $addressId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        return $address;
    }

    /**
     * Build line-item drafts from the request, looking up the active
     * price row per (product_id, term, currency). Soft failures
     * accumulate into a per-line error map → ProductUnavailableException.
     *
     * @param  list<array<string, mixed>>  $itemInputs
     * @return list<array<string, mixed>>
     */
    private function buildLineDrafts(array $itemInputs, string $currency): array
    {
        $errors = [];
        $drafts = [];

        foreach ($itemInputs as $idx => $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $quantity = (int) ($line['quantity'] ?? 0);
            $term = isset($line['term']) ? (string) $line['term'] : ProductTerm::Buy->value;

            /** @var Product|null $product */
            $product = Product::query()->find($productId);
            if ($product === null) {
                $errors["items.$idx"] = [__('orders.product_not_found')];

                continue;
            }
            if (! $product->is_active || $product->stock_status !== StockStatus::InStock) {
                $errors["items.$idx"] = [__('orders.product_out_of_stock')];

                continue;
            }
            if ($product->is_quote_only) {
                $errors["items.$idx"] = [__('orders.product_quote_only')];

                continue;
            }

            /** @var ProductPrice|null $price */
            $price = ProductPrice::query()
                ->where('product_id', $productId)
                ->where('term', $term)
                ->where('is_active', true)
                ->orderByRaw("currency = 'AED' DESC")
                ->orderBy('currency', 'asc')
                ->first();

            if ($price === null) {
                $errors["items.$idx"] = [__('orders.price_not_available', ['currency' => $currency])];

                continue;
            }

            $unit = (float) $price->amount;
            $sub = round($unit * $quantity, 2);
            $translations = $product->getTranslations('name');
            $name = $translations !== []
                ? $translations
                : ['en' => (string) $product->name, 'ar' => (string) $product->name];

            $drafts[] = [
                'product_id' => $productId,
                'product_code' => $product->code,
                'product_name_snapshot' => $name,
                'term' => $term,
                'unit_price' => $unit,
                'quantity' => $quantity,
                'currency' => $price->currency,
                'line_subtotal' => $sub,
                'rent_period' => $price->period,
                'rent_min_term_months' => $price->min_term_months,
                'category_id' => $product->category_id,
            ];
        }

        if ($errors !== []) {
            throw ProductUnavailableException::fromItems($errors);
        }

        return $drafts;
    }

    /**
     * Persist {@see OrderItem} rows from the line drafts, distributing
     * the order-level discount + tax proportionally by line_subtotal
     * share.
     *
     * @param  list<array<string, mixed>>  $drafts
     */
    private function persistLineItems(
        Order $order,
        array $drafts,
        float $orderDiscount,
        float $orderTax,
        float $subtotal,
    ): void {
        foreach ($drafts as $draft) {
            $share = $subtotal > 0
                ? (float) $draft['line_subtotal'] / $subtotal
                : 0.0;
            $lineDiscount = round($orderDiscount * $share, 2);
            $lineTax = round($orderTax * $share, 2);
            $lineTotal = round((float) $draft['line_subtotal'] - $lineDiscount + $lineTax, 2);

            OrderItem::query()->create([
                'order_id' => $order->id,
                'product_id' => $draft['product_id'],
                'product_code' => $draft['product_code'],
                'product_name_snapshot' => $draft['product_name_snapshot'],
                'term' => $draft['term'],
                'unit_price' => number_format((float) $draft['unit_price'], 2, '.', ''),
                'quantity' => (int) $draft['quantity'],
                'currency' => (string) $draft['currency'],
                'line_subtotal' => number_format((float) $draft['line_subtotal'], 2, '.', ''),
                'line_discount' => number_format($lineDiscount, 2, '.', ''),
                'line_tax' => number_format($lineTax, 2, '.', ''),
                'line_total' => number_format($lineTotal, 2, '.', ''),
                'rent_period' => $draft['rent_period'],
                'rent_min_term_months' => $draft['rent_min_term_months'],
            ]);
        }
    }

    /**
     * Compute the customer-facing `next_step` block for the tracking
     * endpoint. Per § 4.5.1 of the architecture doc.
     *
     * @return array<string, mixed>|null
     */
    private function buildNextStep(Order $order): ?array
    {
        if ($order->status->isTerminal()) {
            return null;
        }

        [$nextStatus, $labelKey, $action] = match ($order->status) {
            OrderStatus::Pending => $order->payment_method === PaymentMethod::Cod
                ? [OrderStatus::Confirmed, 'orders.next_step.pending_cod', 'none']
                : [OrderStatus::Confirmed, 'orders.next_step.pending_online', 'customer_complete_payment'],
            OrderStatus::Confirmed => [OrderStatus::Scheduled, 'orders.next_step.confirmed', 'none'],
            OrderStatus::Scheduled => [OrderStatus::OutForDelivery, 'orders.next_step.scheduled', 'none'],
            OrderStatus::OutForDelivery => [OrderStatus::Installed, 'orders.next_step.out_for_delivery', 'customer_be_available'],
            OrderStatus::Installed => [OrderStatus::Completed, 'orders.next_step.installed', 'none'],
            default => [null, null, 'none'],
        };

        if ($nextStatus === null) {
            return null;
        }

        return [
            'status' => $nextStatus->value,
            'label' => __($labelKey),
            'estimated_at' => $order->scheduled_for_at?->toIso8601String(),
            'action_required' => $action,
        ];
    }

    /**
     * 4-hour window centred on `scheduled_for_at`. Null until the order
     * reaches `scheduled` AND has a scheduled_for_at value.
     *
     * @return array<string, string>|null
     */
    private function buildDeliveryWindow(Order $order): ?array
    {
        if ($order->scheduled_for_at === null) {
            return null;
        }

        $centre = $order->scheduled_for_at;

        return [
            'from' => $centre->copy()->subHours(2)->toIso8601String(),
            'to' => $centre->copy()->addHours(2)->toIso8601String(),
        ];
    }

    /**
     * Canonicalise the request payload for idempotency hashing. Stable
     * key ordering + numeric normalisation so semantically-identical
     * requests hash to the same value regardless of JSON insertion order.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function canonicalisePayload(array $payload): array
    {
        ksort($payload);
        $canonical = [];
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $canonical[$key] = $this->canonicalisePayload($value);
            } else {
                $canonical[$key] = $value;
            }
        }

        return $canonical;
    }
}
