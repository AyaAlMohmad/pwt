<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Promotions;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/v1/promotions — no query params on Phase 7.
 *
 * The class is created so a stable extension point exists for the
 * Phase 20 admin variants (status / window filters)
 * (docs/architecture/promotions.md § 3.2).
 */
final class ListPromotionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
