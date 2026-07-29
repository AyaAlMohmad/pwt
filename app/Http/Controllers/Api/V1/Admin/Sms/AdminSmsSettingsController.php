<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Sms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Sms\UpdateSmsSettingsRequest;
use App\Models\Setting;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

final class AdminSmsSettingsController extends Controller
{
    #[OA\Get(
        path: '/api/v1/admin/sms-settings',
        operationId: 'adminSmsSettingsShow',
        description: 'Returns current SMS configuration. Secrets are masked.',
        summary: 'Show SMS settings',
        security: [['sanctum' => []]],
        tags: ['Admin / SMS'],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean'),
                    new OA\Property(property: 'message', type: 'string'),
                    new OA\Property(property: 'data', type: 'object', properties: [
                        new OA\Property(property: 'driver', type: 'string', example: 'stub'),
                        new OA\Property(property: 'sender', type: 'string', example: 'PWT'),
                        new OA\Property(property: 'driver_config', type: 'object', example: []),
                    ]),
                ])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ],
    )]
    public function show(Request $request): JsonResponse
    {
        $driver = (string) Setting::get('sms.driver', config('sms.default', 'stub'));
        $sender = (string) Setting::get('sms.sender', config('sms.sender', 'PWT'));
        $driverConfig = Setting::get('sms.driver_config', []);

        return ApiResponse::success([
            'driver' => $driver,
            'sender' => $sender,
            'driver_config' => $this->maskDriverConfig(
                is_array($driverConfig) ? $driverConfig : []
            ),
        ], __('admin.sms_settings.retrieved'));
    }

    #[OA\Put(
        path: '/api/v1/admin/sms-settings',
        operationId: 'adminSmsSettingsUpdate',
        description: 'Update SMS gateway configuration. Stores settings in the settings table.',
        summary: 'Update SMS settings',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'driver', type: 'string', enum: ['stub', 'twilio', 'unifonic']),
                new OA\Property(property: 'sender', type: 'string', nullable: true),
                new OA\Property(property: 'driver_config', type: 'object', nullable: true),
            ]),
        ),
        tags: ['Admin / SMS'],
        responses: [
            new OA\Response(response: 200, description: 'OK'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ],
    )]
    public function update(UpdateSmsSettingsRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (array_key_exists('driver', $data)) {
            $this->updateSetting('sms.driver', $data['driver']);
        }

        if (array_key_exists('sender', $data)) {
            $this->updateSetting('sms.sender', $data['sender']);
        }

        if (array_key_exists('driver_config', $data)) {
            $this->updateSetting('sms.driver_config', $data['driver_config']);
        }

        return ApiResponse::success([], __('admin.sms_settings.updated'));
    }

    private function updateSetting(string $key, mixed $value): void
    {
        if ($value === null || $value === '') {
            Setting::query()->where('key', $key)->delete();
        } else {
            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => $value]
            );
        }
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function maskDriverConfig(array $config): array
    {
        $masked = [];
        foreach ($config as $k => $v) {
            if (is_string($v) && $this->isSensitiveKey($k)) {
                $masked[$k] = $this->mask($v);
            } else {
                $masked[$k] = $v;
            }
        }

        return $masked;
    }

    private function isSensitiveKey(string $key): bool
    {
        $sensitive = ['token', 'secret', 'password', 'key', 'auth', 'api_key', 'api_secret'];
        $lower = strtolower($key);
        foreach ($sensitive as $word) {
            if (str_contains($lower, $word)) {
                return true;
            }
        }

        return false;
    }

    private function mask(?string $value): ?string
    {
        if ($value === null || $value === '' || strlen($value) <= 4) {
            return $value;
        }

        return str_repeat('*', strlen($value) - 4) . substr($value, -4);
    }
}
