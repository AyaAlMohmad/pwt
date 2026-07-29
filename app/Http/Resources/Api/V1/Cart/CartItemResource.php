<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Cart;

use App\Models\CartItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CartItem
 */
final class CartItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        unset($request);

        $product = $this->whenLoaded('product');

        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'term' => $this->term,
            'quantity' => $this->quantity,
            'product' => $product instanceof \App\Models\Product
                ? [
                    'id' => $product->id,
                    'code' => $product->code,
                    'name' => $product->name,
                    'image_url' => $product->images->first()?->url,
                ]
                : null,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
