<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Notifications;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for `GET /api/v1/notifications`.
 *
 * All filters are optional — an empty query returns the caller's full
 * paginated inbox. The `type` regex is forward-compatible: unknown
 * template keys produce empty results rather than 422 so new event
 * types don't require an API redeploy to silence client errors.
 *
 * See docs/architecture/notifications.md § 4.1.
 */
final class ListNotificationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Auth is handled by Sanctum middleware; ownership is scoped at
        // the service level via `forUser()`.
        return $this->user() !== null;
    }

    /**
     * Coerce the `unread_only` query parameter from common string forms
     * (`"true"`, `"false"`, `"yes"`, `"no"`, `""`) to a real boolean.
     * Laravel's `boolean` rule itself accepts only `1/0/"1"/"0"/true/false`,
     * so without this coercion a caller hitting `?unread_only=true` would
     * get a 422. Garbage values are left intact for the validator to reject.
     *
     * Mirrors the pattern used by `featured` in `ListProductsRequest`.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('unread_only')) {
            $value = $this->input('unread_only');
            if (is_string($value)) {
                $lower = strtolower($value);
                $this->merge([
                    'unread_only' => match ($lower) {
                        'true', '1', 'yes' => true,
                        'false', '0', 'no', '' => false,
                        default => $value, // leave for the validator to reject
                    },
                ]);
            }
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'unread_only' => ['nullable', 'boolean'],
            'type' => ['nullable', 'string', 'max:64', 'regex:/^[a-z]+\.[a-z_]+$/'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.regex' => __('notifications.validation.type_format'),
            'date_to.after_or_equal' => __('notifications.validation.date_range'),
        ];
    }
}
