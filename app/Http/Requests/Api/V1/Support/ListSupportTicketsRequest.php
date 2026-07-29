<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Support;

use App\Enums\SupportTicketCategory;
use App\Enums\SupportTicketStatus;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/v1/support-tickets — query-string validation.
 *
 * The controller scopes the query to `user_id = auth.id`; this Form
 * Request only validates the filter shape.
 *
 * See docs/architecture/support.md § 4.2.
 */
final class ListSupportTicketsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', 'string', 'in:'.implode(',', SupportTicketStatus::values())],
            'category' => ['nullable', 'string', 'in:'.implode(',', SupportTicketCategory::values())],
        ];
    }
}
