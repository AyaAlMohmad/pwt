<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Orders;

use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Per-line shape of an {@see OrderItem}.
 *
 * `product_name` is rendered for the active locale from the bilingual
 * `product_name_snapshot` JSON; this keeps order rendering independent
 * of future product renames.
 *
 * @property-read OrderItem $resource
 */
final class OrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var OrderItem $item */
        $item = $this->resource;
        $locale = app()->getLocale();

        return [
            'id' => $item->id,
            'product_id' => $item->product_id,
            'product_code' => $item->product_code,
            'product_name' => $item->getTranslation('product_name_snapshot', $locale, false)
                ?: $item->getTranslation('product_name_snapshot', config('app.fallback_locale', 'en')),
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
