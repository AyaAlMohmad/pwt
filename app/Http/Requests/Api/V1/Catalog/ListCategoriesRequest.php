<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Catalog;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/v1/categories — query-string validation.
 *
 * The endpoint is public, so `authorize()` returns `true`. The only
 * query parameter is the optional `search` substring (≤ 100 chars). All
 * other visibility filters are applied server-side
 * (docs/architecture/catalog.md § 3.1, § 1.4).
 */
final class ListCategoriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }
}
