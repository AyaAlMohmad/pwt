<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Notifications;

use App\Http\Controllers\Controller;
use App\Models\UserDeviceToken;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Register / unregister FCM device tokens from the mobile app.
 */
final class PushTokenController extends Controller
{
    /**
     * Register or refresh a device token for the authenticated user.
     *
     * If the same device_token is already linked to a different user,
     * it is reassigned to the currently authenticated user so that
     * push notifications do not leak across accounts.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_token' => ['required', 'string', 'max:500'],
            'platform' => ['required', 'string', 'in:android,ios,web'],
            'device_name' => ['nullable', 'string', 'max:100'],
            'app_version' => ['nullable', 'string', 'max:20'],
        ]);

        $userId = (int) $request->user()->id;

        // Reassign token if it belongs to a different user (same device, new login).
        UserDeviceToken::query()
            ->where('device_token', $validated['device_token'])
            ->where('user_id', '!=', $userId)
            ->delete();

        UserDeviceToken::query()->updateOrCreate(
            [
                'user_id' => $userId,
                'device_token' => $validated['device_token'],
            ],
            [
                'platform' => $validated['platform'],
                'device_name' => $validated['device_name'] ?? null,
                'app_version' => $validated['app_version'] ?? null,
                'is_active' => true,
                'last_used_at' => now(),
            ]
        );

        return ApiResponse::success(null, 'Device token registered.');
    }

    /**
     * Unregister (deactivate) a device token for the authenticated user.
     */
    public function unregister(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_token' => ['required', 'string', 'max:500'],
        ]);

        $userId = (int) $request->user()->id;

        UserDeviceToken::query()
            ->where('user_id', $userId)
            ->where('device_token', $validated['device_token'])
            ->update(['is_active' => false]);

        return ApiResponse::success(null, 'Device token unregistered.');
    }
}
