<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Request body for PUT /api/v1/admin/categories/{category}.
 *
 * PUT semantics: every field that the admin wants to keep MUST be
 * re-sent. AR translation falls back to existing stored value when the
 * locale key is absent — Spatie merges the provided locales onto the
 * stored JSON.
 *
 * `code` IS editable here (unlike on products — § 9.4) and must remain
 * unique among non-trashed rows excluding the current id.
 *
 * Self-parent + cycle prevention is implemented in `withValidator()`
 * because it requires the resolved {category} model.
 *
 * See docs/architecture/admin-catalog.md § 3.3.
 */
final class UpdateCategoryRequest extends FormRequest
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
        $categoryId = (int) $this->route('category');

        return [
            'code' => [
                'required',
                'string',
                'min:2',
                'max:80',
                'regex:/^[a-z0-9-]+$/',
                Rule::unique('categories', 'code')->ignore($categoryId)->whereNull('deleted_at'),
            ],
            'name' => ['required', 'array'],
            'name.en' => ['required', 'string', 'min:2', 'max:120'],
            'name.ar' => ['nullable', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'array'],
            'description.en' => ['nullable', 'string', 'max:5000'],
            'description.ar' => ['nullable', 'string', 'max:5000'],
            'image_url' => ['nullable', 'string', 'max:500'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['nullable', 'boolean'],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id', 'different:'.$categoryId],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $parentId = $this->input('parent_id');
            $categoryId = (int) $this->route('category');

            if ($parentId === null || (int) $parentId === $categoryId) {
                return;
            }

            // Walk up to 5 levels to detect a cycle (parent → … → self).
            $current = (int) $parentId;
            for ($i = 0; $i < 5; $i++) {
                $row = DB::table('categories')
                    ->select(['id', 'parent_id'])
                    ->where('id', $current)
                    ->first();

                if ($row === null) {
                    return;
                }
                $next = $row->parent_id ?? null;
                if ($next === null) {
                    return;
                }
                if ((int) $next === $categoryId) {
                    $v->errors()->add('parent_id', 'The parent_id would create a cycle.');

                    return;
                }
                $current = (int) $next;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'The code must contain only lowercase letters, digits and hyphens.',
            'name.required' => 'The name field is required (bilingual object expected).',
            'name.array' => 'The name field must be an object with at least an "en" key.',
            'name.en.required' => 'The English (en) translation is required for the name.',
            'parent_id.different' => 'A category cannot be its own parent.',
        ];
    }
}
