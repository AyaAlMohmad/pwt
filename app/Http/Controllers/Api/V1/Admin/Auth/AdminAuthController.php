<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Auth\AdminLoginRequest;
use App\Http\Resources\Api\V1\Admin\Auth\AdminAuthSuccessResource;
use App\Http\Resources\Api\V1\Admin\Auth\AdminMeResource;
use App\Models\User;
use App\Services\Auth\AdminAuthService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

final class AdminAuthController extends Controller
{
    public function __construct(
        private readonly AdminAuthService $adminAuthService,
    ) {}

    #[OA\Post(
        path: '/api/v1/admin/auth/login',
        operationId: 'adminAuthLogin',
        description: 'Authenticate an admin staff member by email + password. Rejects customers and inactive accounts with the same 401 envelope (anti-enumeration). On success returns the admin profile, an 8-hour Sanctum token (ability `admin`), the role name, the granted permission keys, and a derived capability map.',
        summary: 'Admin login',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/AdminLoginRequest'),
        ),
        tags: ['Admin / Auth'],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminAuthSuccessResponse')),
            new OA\Response(response: 401, description: 'Invalid credentials',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Admin account disabled',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function login(AdminLoginRequest $request): JsonResponse
    {
        $result = $this->adminAuthService->login(
            (string) $request->input('email'),
            (string) $request->input('password'),
            $request->ip(),
        );

        return ApiResponse::success(
            (new AdminAuthSuccessResource($result))->resolve($request),
            __('admin.login_success'),
        );
    }

    #[OA\Post(
        path: '/api/v1/admin/auth/logout',
        operationId: 'adminAuthLogout',
        description: 'Revoke the current admin Sanctum token. Other tokens belonging to the same admin (e.g. another device or browser) are unaffected.',
        summary: 'Admin logout (current token only)',
        security: [['sanctum' => []]],
        tags: ['Admin / Auth'],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/LogoutResponse')),
            new OA\Response(response: 401, description: 'Not authenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Not an admin',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function logout(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $this->adminAuthService->logout($admin);

        return ApiResponse::success(null, __('admin.logout_success'));
    }

    #[OA\Get(
        path: '/api/v1/admin/me',
        operationId: 'adminMe',
        description: 'Returns the current admin profile, role, granted permission keys, and capability map. Used by the dashboard shell to render navigation and gate pages.',
        summary: 'Current admin profile',
        security: [['sanctum' => []]],
        tags: ['Admin / Auth'],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/AdminMeResponse')),
            new OA\Response(response: 401, description: 'Not authenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Not an admin',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function me(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $admin->load('roles.permissions');

        return ApiResponse::success(
            (new AdminMeResource($admin))->resolve($request),
            __('admin.me_ok'),
        );
    }
}
