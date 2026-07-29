<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Catalog;

use App\Models\ProductPrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read ProductPrice $resource
 */
final class ProductPriceResource extends JsonResource
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
        ];
    }
}
