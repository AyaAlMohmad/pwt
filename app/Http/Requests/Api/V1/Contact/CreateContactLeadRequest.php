<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Contact;

use App\Enums\ContactLeadSource;
use App\Enums\ContactLeadType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/contact — body validation.
 *
 * Public endpoint (no auth). The `website` field is a honeypot — it MUST
 * be absent or empty. If a bot submits a non-empty value, we still
 * return a normal 201-shaped success response from the controller but
 * NO row is persisted (the service drops it). The Form Request keeps
 * `website` in the validated payload so the service can detect + drop.
 *
 * See docs/architecture/contact.md § 3.1.
 */
final class CreateContactLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Defensive coercion: trim every input string + lowercase the email.
     * Defaults `source` to `web` when missing.
     */
    protected function prepareForValidation(): void
    {
        $patches = [];

        foreach (['name', 'email', 'phone', 'phone_country_code', 'subject', 'message', 'source', 'type', 'website'] as $field) {
            $raw = $this->input($field);
            if (is_string($raw)) {
                $patches[$field] = trim($raw);
            }
        }

        if (isset($patches['email']) && $patches['email'] !== '') {
            $patches['email'] = mb_strtolower($patches['email']);
        }

        if (! isset($patches['source']) || $patches['source'] === '') {
            $patches['source'] = ContactLeadSource::Web->value;
        }

        if (! isset($patches['type']) || $patches['type'] === '') {
            $patches['type'] = ContactLeadType::General->value;
        }

        $this->merge($patches);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $emailRules = ['required', 'string', 'max:200'];
        $emailRules[] = (bool) config('contact.validate_email_dns', true)
            ? 'email:rfc,dns'
            : 'email:rfc';

        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => $emailRules,
            'phone' => ['nullable', 'string', 'max:30'],
            'phone_country_code' => ['nullable', 'string', 'regex:/^\+?[0-9]{1,5}$/', 'max:6'],
            'subject' => ['required', 'string', 'min:3', 'max:200'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'source' => ['nullable', 'string', Rule::in(ContactLeadSource::values())],
            'type' => ['nullable', 'string', Rule::in(ContactLeadType::values())],

            // Honeypot — NOT documented in OpenAPI. Optional + allowed in
            // payload so we can detect a bot fill; the service drops the
            // row silently when non-empty.
            'website' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => __('contact.name_required'),
            'name.min' => __('contact.name_min'),
            'name.max' => __('contact.name_max'),
            'email.required' => __('contact.email_required'),
            'email.email' => __('contact.email_invalid'),
            'email.max' => __('contact.email_max'),
            'phone.max' => __('contact.phone_invalid'),
            'phone_country_code.regex' => __('contact.phone_invalid'),
            'phone_country_code.max' => __('contact.phone_invalid'),
            'subject.required' => __('contact.subject_required'),
            'subject.min' => __('contact.subject_min'),
            'subject.max' => __('contact.subject_max'),
            'message.required' => __('contact.message_required'),
            'message.min' => __('contact.message_min'),
            'message.max' => __('contact.message_max'),
            'source.in' => __('contact.source_invalid'),
            'type.in' => __('contact.type_invalid'),
        ];
    }
}
