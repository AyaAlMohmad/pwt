<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Payments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Payments\UpdatePaymentSettingsRequest;
use App\Models\Setting;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

final class AdminPaymentSettingsController extends Controller
{
    #[OA\Get(
        path: '/api/v1/admin/payment-settings',
        operationId: 'adminPaymentSettingsShow',
        description: 'Returns current payment gateway configuration. Secrets are masked.',
        summary: 'Show payment settings',
        security: [['sanctum' => []]],
        tags: ['Admin / Payments'],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean'),
                    new OA\Property(property: 'message', type: 'string'),
                    new OA\Property(property: 'data', type: 'object', properties: [
                        new OA\Property(property: 'driver', type: 'string', example: 'stripe'),
                        new OA\Property(property: 'stripe', type: 'object', properties: [
                            new OA\Property(property: 'publishable_key', type: 'string', example: 'pk_test****XY2'),
                            new OA\Property(property: 'secret_key', type: 'string', example: 'sk_test****mbW'),
                            new OA\Property(property: 'webhook_secret', type: 'string', example: 'whsec****1234'),
                            new OA\Property(property: 'return_url', type: 'string', example: 'http://pwt.com/payments/success'),
                            new OA\Property(property: 'cancel_url', type: 'string', example: 'http://pwt.com/payments/cancel'),
                        ]),
                    ]),
                ])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ],
    )]
    public function show(Request $request): JsonResponse
    {
        $driver = Setting::get('payment.default', config('payment.default', 'stub'));

        return ApiResponse::success([
            'driver' => $driver,
            'stripe' => [
                'publishable_key' => $this->mask(
                    (string) Setting::get('payment.stripe.publishable_key', config('payment.stripe.publishable_key', ''))
                ),
                'secret_key' => $this->mask(
                    (string) Setting::get('payment.stripe.secret_key', config('payment.stripe.secret_key', ''))
                ),
                'webhook_secret' => $this->mask(
                    (string) Setting::get('payment.stripe.webhook_secret', config('payment.stripe.webhook_secret', ''))
                ),
                'return_url' => (string) Setting::get('payment.stripe.return_url', config('payment.stripe.return_url', '')),
                'cancel_url' => (string) Setting::get('payment.stripe.cancel_url', config('payment.stripe.cancel_url', '')),
            ],
        ], __('admin.payment_settings.retrieved'));
    }

    #[OA\Put(
        path: '/api/v1/admin/payment-settings',
        operationId: 'adminPaymentSettingsUpdate',
        description: 'Update payment gateway configuration. Stores keys in the settings table so they persist without editing .env.',
        summary: 'Update payment settings',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'driver', type: 'string', enum: ['stub', 'stripe', 'payfort']),
                new OA\Property(property: 'stripe', type: 'object', properties: [
                    new OA\Property(property: 'publishable_key', type: 'string', nullable: true),
                    new OA\Property(property: 'secret_key', type: 'string', nullable: true),
                    new OA\Property(property: 'webhook_secret', type: 'string', nullable: true),
                    new OA\Property(property: 'return_url', type: 'string', nullable: true),
                    new OA\Property(property: 'cancel_url', type: 'string', nullable: true),
                ]),
            ]),
        ),
        tags: ['Admin / Payments'],
        responses: [
            new OA\Response(response: 200, description: 'OK'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ],
    )]
    public function update(UpdatePaymentSettingsRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (isset($data['driver'])) {
            Setting::updateOrCreate(
                ['key' => 'payment.default'],
                ['value' => $data['driver']]
            );
        }

        if (isset($data['stripe'])) {
            $this->syncStripeSettings($data['stripe']);
        }

        return ApiResponse::success([], __('admin.payment_settings.updated'));
    }

    /**
     * @param  array<string, mixed>  $stripe
     */
    private function syncStripeSettings(array $stripe): void
    {
        $keys = [
            'publishable_key',
            'secret_key',
            'webhook_secret',
            'return_url',
            'cancel_url',
        ];

        foreach ($keys as $key) {
            if (array_key_exists($key, $stripe)) {
                $value = $stripe[$key];
                if ($value === null || $value === '') {
                    Setting::query()->where('key', "payment.stripe.$key")->delete();
                } else {
                    Setting::updateOrCreate(
                        ['key' => "payment.stripe.$key"],
                        ['value' => $value]
                    );
                }
            }
        }
    }

    private function mask(?string $value): ?string
    {
        if ($value === null || $value === '' || strlen($value) <= 12) {
            return $value;
        }

        return substr($value, 0, 6) . '****' . substr($value, -4);
    }
}
