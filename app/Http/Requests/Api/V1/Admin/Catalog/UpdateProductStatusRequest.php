<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Request body for PATCH /api/v1/admin/products/{product}/status.
 *
 * Thin toggle endpoint. Accepts any subset of `is_active`,
 * `is_featured`, `is_quote_only`, `stock_status`. At least ONE must be
 * present (enforced in `withValidator()` since `sometimes` alone would
 * silently accept an empty body).
 *
 * Mutually exclusive cross-fields (e.g. quote_only=true + active prices
 * exist) are validated in the service layer because they require a DB
 * lookup.
 *
 * See docs/architecture/admin-catalog.md § 3.7.
 */
final class UpdateProductStatusRequest extends FormRequest
{
    /** @var list<string> */
    private const MUTABLE_FIELDS = [
        'is_active',
        'is_featured',
        'is_quote_only',
        'individual_buy_enabled',
        'stock_status',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'is_active' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'is_quote_only' => ['sometimes', 'boolean'],
            'individual_buy_enabled' => ['sometimes', 'boolean'],
            'stock_status' => ['sometimes', 'string', 'in:in_stock,out_of_stock,coming_soon,discontinued'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $hasMutable = false;
            foreach (self::MUTABLE_FIELDS as $field) {
                if ($this->has($field)) {
                    $hasMutable = true;
                    break;
                }
            }
            if (! $hasMutable) {
                $v->errors()->add('body', 'At least one status flag must be provided.');
            }
        });
    }
}
