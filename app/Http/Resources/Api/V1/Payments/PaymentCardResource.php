<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Payments;

use App\Models\PaymentCard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read PaymentCard $resource
 */
final class PaymentCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PaymentCard $card */
        $card = $this->resource;

        return [
            'id' => $card->id,
            'brand' => $card->brand,
            'last_four' => $card->last_four,
            'card_number_hash' => $card->card_number_hash,
            'cvc' => $card->cvc,
            'expiry_month' => $card->expiry_month,
            'expiry_year' => $card->expiry_year,
            'cardholder_name' => $card->cardholder_name,
            'is_default' => (bool) $card->is_default,
            'created_at' => $card->created_at?->toIso8601String(),
            'updated_at' => $card->updated_at?->toIso8601String(),
        ];
    }
}
