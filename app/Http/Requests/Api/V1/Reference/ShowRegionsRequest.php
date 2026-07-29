<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Reference;

use App\Support\Http\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validate the `{code}` path parameter on
 * `GET /api/v1/countries/{code}/regions`.
 *
 * The architect spec maps a malformed code to HTTP 400 (not 422) per
 * CLAUDE.md § 5.3 — overriding `failedValidation()` lets us preserve
 * the standard envelope while emitting the documented status code.
 */
final class ShowRegionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Lowercase the path-param before the rules fire so the regex
     * always matches on the canonical form.
     */
    protected function prepareForValidation(): void
    {
        /** @var array<string, mixed> $route */
        $route = $this->route() !== null ? $this->route()->parameters() : [];
        $code = $route['code'] ?? null;

        if (is_string($code)) {
            $normalised = strtolower($code);
            $this->merge(['code' => $normalised]);

            $routeInstance = $this->route();
            if ($routeInstance !== null) {
                $routeInstance->setParameter('code', $normalised);
            }
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'size:2', 'regex:/^[a-z]{2}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => __('validation.required', ['attribute' => 'country code']),
            'code.size' => __('reference.country_code_invalid'),
            'code.regex' => __('reference.country_code_invalid'),
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(
            ApiResponse::error(
                __('reference.country_code_invalid'),
                Response::HTTP_BAD_REQUEST,
                code: 'ERR_INVALID_COUNTRY_CODE',
            ),
        );
    }
}
