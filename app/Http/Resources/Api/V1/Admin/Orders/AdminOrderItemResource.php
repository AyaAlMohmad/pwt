<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Orders;

use App\Http\Resources\Api\V1\Orders\OrderItemResource;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin shape of an {@see OrderItem}.
 *
 * Same field set as the customer-facing
 * {@see OrderItemResource} — kept as a
 * separate class so future admin-only fields can be added without
 * touching the customer surface.
 *
 * @property-read OrderItem $resource
 */
final class AdminOrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        unset($request);
        /** @var OrderItem $item */
        $item = $this->resource;
        $locale = app()->getLocale();

        return [
            'id' => $item->id,
            'product_id' => $item->product_id,
            'product_code' => $item->product_code,
            'product_name' => $item->getTranslation('product_name_snapshot', $locale, false)
                ?: $item->getTranslation('product_name_snapshot', (string) config('app.fallback_locale', 'en')),
            'plan' => $item->term->value,
            'term' => $item->term->value,
            'unit_price' => (string) $item->unit_price,
            'quantity' => (int) $item->quantity,
            'currency' => $item->currency,
            'line_subtotal' => (string) $item->line_subtotal,
            'line_discount' => (string) $item->line_discount,
            'line_tax' => (string) $item->line_tax,
            'line_total' => (string) $item->line_total,
            'rent_period' => $item->rent_period,
            'rent_min_term_months' => $item->rent_min_term_months,
        ];
    }
}
