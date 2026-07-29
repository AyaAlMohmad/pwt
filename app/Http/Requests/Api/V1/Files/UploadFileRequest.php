<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Files;

use App\Enums\FileType;
use App\Support\Http\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;

/**
 * Request body for POST /api/v1/files (multipart/form-data).
 *
 * Two security concerns drive the rule set:
 *
 *   1. Extension vs sniffed mime mismatch — both `mimes:` and
 *      `mimetypes:` are applied so a renamed `evil.php` posted with
 *      `Content-Type: image/png` is rejected.
 *   2. Body-size cap — Laravel's `max:` rule fires for files that arrive
 *      intact, but PHP's `post_max_size` may drop the request before
 *      validation runs. The {@see prepareForValidation()} tripwire turns
 *      that case into a clean 413 instead of a confusing 422 / 500.
 */
final class UploadFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        // Tripwire — when PHP's post_max_size is exceeded the body never
        // reaches us as $_FILES, but CONTENT_LENGTH still reports the
        // wire size. Map that case to a clean 413 before the validator
        // emits a misleading "file is required" 422.
        $maxBytes = ((int) config('upload.max_kb', 5120)) * 1024;
        $contentLength = (int) ($this->server('CONTENT_LENGTH') ?? 0);
        $fileMissing = ! ($this->file('file') instanceof UploadedFile);

        if ($fileMissing && $contentLength > $maxBytes) {
            throw new HttpResponseException(
                ApiResponse::error(
                    __('files.too_large'),
                    413,
                    null,
                    'ERR_FILE_TOO_LARGE',
                ),
            );
        }

        $type = $this->input('type');
        if (is_string($type)) {
            $this->merge(['type' => strtolower($type)]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $maxKb = (int) config('upload.max_kb', 5120);
        $allowedExtensions = implode(',', (array) config('upload.allowed_extensions', ['jpg', 'jpeg', 'png', 'webp', 'pdf']));
        $allowedMimes = implode(',', (array) config('upload.allowed_mimes', ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']));

        return [
            'file' => [
                'required',
                'file',
                'max:'.$maxKb,
                'mimes:'.$allowedExtensions,
                'mimetypes:'.$allowedMimes,
            ],
            'type' => ['required', 'string', 'in:'.implode(',', FileType::values())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => __('files.required'),
            'file.file' => __('files.required'),
            'file.max' => __('files.too_large'),
            'file.mimes' => __('files.mime_rejected'),
            'file.mimetypes' => __('files.mime_rejected'),
            'type.required' => __('files.type_required'),
            'type.in' => __('files.type_invalid'),
        ];
    }

    /**
     * Override the default 422 envelope to map specific validator
     * failures onto the correct HTTP semantics:
     *
     *  - `file.max` → 413 with code `ERR_FILE_TOO_LARGE`
     *  - `file.mimes` / `file.mimetypes` → 422 with code `ERR_FILE_MIME_REJECTED`
     *  - anything else → 422 with the generic `ERR_VALIDATION`
     */
    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors()->toArray();
        $failed = $validator->failed();

        // `failed()` returns ['field' => ['Rule' => [...args]]]; check the
        // RAW rule names rather than the localised message.
        $fileFailures = isset($failed['file']) && is_array($failed['file']) ? $failed['file'] : [];
        $fileRuleKeys = array_map(static fn (string $r): string => strtolower($r), array_keys($fileFailures));

        if (in_array('max', $fileRuleKeys, true)) {
            throw new HttpResponseException(
                ApiResponse::error(
                    __('files.too_large'),
                    413,
                    $errors,
                    'ERR_FILE_TOO_LARGE',
                ),
            );
        }

        if (in_array('mimes', $fileRuleKeys, true) || in_array('mimetypes', $fileRuleKeys, true)) {
            throw new HttpResponseException(
                ApiResponse::error(
                    __('files.mime_rejected'),
                    422,
                    $errors,
                    'ERR_FILE_MIME_REJECTED',
                ),
            );
        }

        throw new HttpResponseException(
            ApiResponse::error(
                __('messages.validation_failed'),
                422,
                $errors,
                'ERR_VALIDATION',
            ),
        );
    }
}
