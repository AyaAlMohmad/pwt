<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Catalog;

use App\Models\ProductPrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin-side price row shape — exposes `is_active`, `created_at`,
 * `updated_at` so the admin can manage archived rows.
 *
 * @property-read ProductPrice $resource
 */
final class AdminProductPriceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProductPrice $price */
        $price = $this->resource;

        return [
            'id' => $price->id,
            'term' => $price->term->value,
            'amount' => (string) $price->amount,
            'currency' => $price->currency,
            'period' => $price->period,
            'min_term_months' => $price->min_term_months !== null ? (int) $price->min_term_months : null,
            'is_active' => (bool) $price->is_active,
            'created_at' => $price->created_at->toIso8601String(),
            'updated_at' => $price->updated_at->toIso8601String(),
        ];
    }
}
