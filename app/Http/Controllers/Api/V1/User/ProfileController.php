<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\User;

use App\Enums\FileType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\User\DeleteAccountRequest;
use App\Http\Requests\Api\V1\User\UpdateAvatarRequest;
use App\Http\Requests\Api\V1\User\UpdateProfileRequest;
use App\Http\Resources\Api\V1\User\MeResponseResource;
use App\Models\User;
use App\Services\Files\FileStorageService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use OpenApi\Attributes as OA;

final class ProfileController extends Controller
{
    #[OA\Get(
        path: '/api/v1/me',
        operationId: 'meShow',
        description: 'Returns the authenticated customer profile (self-only). The token already binds the user — no path parameter is accepted.',
        summary: 'Get authenticated customer profile',
        security: [['sanctum' => []]],
        tags: ['User'],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/MeResponse')),
            new OA\Response(response: 401, description: 'Not authenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->load(['roles', 'avatar']);

        return ApiResponse::success(
            (new MeResponseResource($user))->resolve($request),
            __('messages.ok'),
        );
    }

    #[OA\Patch(
        path: '/api/v1/me',
        operationId: 'meUpdate',
        description: 'Partial update of the authenticated profile. Changing `email`, `phone`, or `password` requires `current_password`. Phone must be unique and is normalised to E.164 before storage.',
        summary: 'Update authenticated profile',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateProfileRequest'),
        ),
        tags: ['User'],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/MeResponse')),
            new OA\Response(response: 401, description: 'Not authenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorize('update', $user);

        $changes = array_filter([
            'name' => $request->input('name'),
            'first_name' => $request->input('first_name'),
            'last_name' => $request->input('last_name'),
            'email' => $request->has('email') ? $request->input('email') : null,
            'locale' => $request->input('locale'),
            'company_name' => $request->input('company_name'),
        ], static fn ($v) => $v !== null);

        // Handle password change
        $newPassword = null;
        if ($request->has('password') && $request->filled('password')) {
            $newPassword = Hash::make($request->input('password'));
        }

        if ($request->has('email')) {
            $changes['email'] = $request->input('email');
        }

        if ($request->has('phone_country_code')) {
            $changes['phone_country_code'] = $request->input('phone_country_code');
        }

        if ($request->has('phone')) {
            $changes['phone'] = $request->input('phone');
        }

        if ($changes !== [] || $newPassword !== null) {
            DB::transaction(function () use ($user, $changes, $newPassword): void {
                if ($newPassword !== null) {
                    $user->password = $newPassword;
                }
                if ($changes !== []) {
                    $user->fill($changes);
                }
                $user->save();
            });
        }

        $user->refresh()->load(['roles', 'avatar']);

        return ApiResponse::success(
            (new MeResponseResource($user))->resolve($request),
            __('auth.profile_updated'),
        );
    }

    #[OA\Post(
        path: '/api/v1/me/avatar',
        operationId: 'meUpdateAvatar',
        description: 'Upload or replace the authenticated user avatar image. The previous avatar file is soft-deleted. Accepts jpeg/png/webp up to 5 MB.',
        summary: 'Update user avatar',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(ref: '#/components/schemas/UpdateAvatarRequest'),
            ),
        ),
        tags: ['User'],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/MeResponse')),
            new OA\Response(response: 401, description: 'Not authenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function updateAvatar(UpdateAvatarRequest $request, FileStorageService $files): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $upload = $request->file('avatar');
        $file = $files->store($upload, $user, FileType::ProfileAvatar);

        DB::transaction(function () use ($user, $file, $files): void {
            if ($user->avatar_file_id !== null) {
                $oldAvatar = $user->avatar;
                if ($oldAvatar !== null) {
                    $files->softDelete($oldAvatar);
                }
            }

            $user->forceFill(['avatar_file_id' => $file->id])->save();
        });

        $user->refresh()->load('roles', 'avatar');

        return ApiResponse::success(
            (new MeResponseResource($user))->resolve($request),
            __('auth.avatar_updated'),
        );
    }

    #[OA\Delete(
        path: '/api/v1/me',
        operationId: 'meDelete',
        description: 'Soft-delete the authenticated account. Requires the current password AND `confirm: "DELETE"` literal. Revokes ALL tokens for the user; the account remains restorable by a super_admin.',
        summary: 'Delete the authenticated account',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/DeleteAccountRequest'),
        ),
        tags: ['User'],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/DeleteAccountResponse')),
            new OA\Response(response: 401, description: 'Not authenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(DeleteAccountRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authorize('delete', $user);

        DB::transaction(function () use ($user): void {
            $user->tokens()->delete();
            $user->forceFill(['is_active' => false])->save();
            $user->delete();
        });

        return ApiResponse::success(null, __('auth.account_deleted'));
    }
}
