<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Templates;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body validation for PUT /api/v1/admin/notification-templates/{id}.
 *
 * Authorisation (`$this->authorize('update', $template)`) runs in the
 * controller after the service resolves the row.
 *
 * Read-only fields (`key`, `channel`, `placeholders`, `id`, timestamps) are
 * stripped in {@see prepareForValidation()} so the seeder remains the
 * single source of truth for the template catalogue (defensive contract —
 * client never gets a 422 noise for those fields, the values are simply
 * ignored).
 *
 * Bilingual JSON pattern per CLAUDE.md § 8 — both `en` and `ar` are
 * required for `subject` and `body`.
 *
 * See docs/architecture/admin-audit-templates-dashboard.md § 3.3.
 */
final class UpdateTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->offsetUnset('id');
        $this->offsetUnset('key');
        $this->offsetUnset('channel');
        $this->offsetUnset('placeholders');
        $this->offsetUnset('created_at');
        $this->offsetUnset('updated_at');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'array:en,ar'],
            'subject.en' => ['required', 'string', 'max:255'],
            'subject.ar' => ['required', 'string', 'max:255'],
            'body' => ['required', 'array:en,ar'],
            'body.en' => ['required', 'string', 'max:8000'],
            'body.ar' => ['required', 'string', 'max:8000'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'subject.required' => __('admin.templates.subject_required'),
            'subject.en.required' => __('admin.templates.subject_en_required'),
            'subject.ar.required' => __('admin.templates.subject_ar_required'),
            'body.required' => __('admin.templates.body_required'),
            'body.en.required' => __('admin.templates.body_en_required'),
            'body.ar.required' => __('admin.templates.body_ar_required'),
            'is_active.required' => __('admin.templates.is_active_required'),
        ];
    }
}
