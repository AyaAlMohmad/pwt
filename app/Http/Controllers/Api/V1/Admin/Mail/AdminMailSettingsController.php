<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Mail;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Mail\UpdateMailSettingsRequest;
use App\Models\Setting;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

final class AdminMailSettingsController extends Controller
{
    #[OA\Get(
        path: '/api/v1/admin/mail-settings',
        operationId: 'adminMailSettingsShow',
        description: 'Returns current mail configuration. Password is masked.',
        summary: 'Show mail settings',
        security: [['sanctum' => []]],
        tags: ['Admin / Mail'],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean'),
                    new OA\Property(property: 'message', type: 'string'),
                    new OA\Property(property: 'data', type: 'object', properties: [
                        new OA\Property(property: 'driver', type: 'string', example: 'smtp'),
                        new OA\Property(property: 'host', type: 'string', example: 'smtp.mailtrap.io'),
                        new OA\Property(property: 'port', type: 'integer', example: 587),
                        new OA\Property(property: 'encryption', type: 'string', example: 'tls'),
                        new OA\Property(property: 'username', type: 'string', example: 'user@example.com'),
                        new OA\Property(property: 'password', type: 'string', example: '****'),
                        new OA\Property(property: 'from_address', type: 'string', example: 'noreply@pwt.com'),
                        new OA\Property(property: 'from_name', type: 'string', example: 'PWT Mega Tech'),
                    ]),
                ])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ],
    )]
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'driver' => (string) Setting::get('mail.driver', config('mail.default', 'log')),
            'host' => (string) Setting::get('mail.host', config('mail.mailers.smtp.host', '')),
            'port' => (int) Setting::get('mail.port', config('mail.mailers.smtp.port', 587)),
            'encryption' => (string) Setting::get('mail.encryption', config('mail.mailers.smtp.encryption', 'tls')),
            'username' => (string) Setting::get('mail.username', config('mail.mailers.smtp.username', '')),
            'password' => $this->mask(
                (string) Setting::get('mail.password', config('mail.mailers.smtp.password', ''))
            ),
            'from_address' => (string) Setting::get('mail.from_address', config('mail.from.address', '')),
            'from_name' => (string) Setting::get('mail.from_name', config('mail.from.name', '')),
        ], __('admin.mail_settings.retrieved'));
    }

    #[OA\Put(
        path: '/api/v1/admin/mail-settings',
        operationId: 'adminMailSettingsUpdate',
        description: 'Update mail server configuration. Stores settings in the settings table so they persist without editing .env.',
        summary: 'Update mail settings',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'driver', type: 'string', enum: ['smtp', 'log', 'sendmail', 'ses', 'postmark']),
                new OA\Property(property: 'host', type: 'string', nullable: true),
                new OA\Property(property: 'port', type: 'integer', nullable: true),
                new OA\Property(property: 'encryption', type: 'string', enum: ['tls', 'ssl', 'null'], nullable: true),
                new OA\Property(property: 'username', type: 'string', nullable: true),
                new OA\Property(property: 'password', type: 'string', nullable: true),
                new OA\Property(property: 'from_address', type: 'string', format: 'email', nullable: true),
                new OA\Property(property: 'from_name', type: 'string', nullable: true),
            ]),
        ),
        tags: ['Admin / Mail'],
        responses: [
            new OA\Response(response: 200, description: 'OK'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ],
    )]
    public function update(UpdateMailSettingsRequest $request): JsonResponse
    {
        $data = $request->validated();

        $stringKeys = [
            'driver',
            'host',
            'encryption',
            'username',
            'password',
            'from_address',
            'from_name',
        ];

        foreach ($stringKeys as $key) {
            if (array_key_exists($key, $data)) {
                $value = $data[$key];
                if ($value === null || $value === '') {
                    Setting::query()->where('key', "mail.$key")->delete();
                } else {
                    Setting::updateOrCreate(
                        ['key' => "mail.$key"],
                        ['value' => $value]
                    );
                }
            }
        }

        if (array_key_exists('port', $data)) {
            $port = $data['port'];
            if ($port === null || $port === '') {
                Setting::query()->where('key', 'mail.port')->delete();
            } else {
                Setting::updateOrCreate(
                    ['key' => 'mail.port'],
                    ['value' => (int) $port]
                );
            }
        }

        return ApiResponse::success([], __('admin.mail_settings.updated'));
    }

    private function mask(?string $value): ?string
    {
        if ($value === null || $value === '' || strlen($value) <= 4) {
            return $value;
        }

        return str_repeat('*', strlen($value) - 4) . substr($value, -4);
    }
}
