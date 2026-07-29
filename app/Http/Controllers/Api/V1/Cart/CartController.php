<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Cart;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Cart\AddToCartRequest;
use App\Http\Requests\Api\V1\Cart\UpdateCartItemRequest;
use App\Http\Resources\Api\V1\Cart\CartResource;
use App\Models\CartItem;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Support\Http\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Authenticated shopping-cart controller.
 *
 * One persistent cart per user.
 */
final class CartController extends Controller
{
    public function __construct(
        private readonly CartService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/cart',
        operationId: 'cartShow',
        description: "Show the caller's shopping cart with items and product previews. Creates the cart lazily if it does not yet exist.",
        summary: 'Get cart',
        security: [['sanctum' => []]],
        tags: ['Cart'],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/CartResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $cart = $this->service->getOrCreate($user);

        return ApiResponse::success(
            new CartResource($cart),
            __('cart.retrieved'),
        );
    }

    #[OA\Post(
        path: '/api/v1/cart/items',
        operationId: 'cartAddItem',
        description: 'Add a product to the cart. If the same product+term already exists, quantity is incremented. Rate-limited at 30/min/user.',
        summary: 'Add item to cart',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/AddToCartRequest'),
        ),
        tags: ['Cart'],
        responses: [
            new OA\Response(response: 200, description: 'OK (item added or quantity incremented)',
                content: new OA\JsonContent(ref: '#/components/schemas/CartResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 429, description: 'Rate limited',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function addItem(AddToCartRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $cart = $this->service->getOrCreate($user);

        $this->service->addItem($cart, $request->validated());
        $cart->load('items.product.images');

        return ApiResponse::success(
            new CartResource($cart),
            __('cart.item_added'),
        );
    }

    #[OA\Patch(
        path: '/api/v1/cart/items/{id}',
        operationId: 'cartUpdateItem',
        description: 'Update the quantity of a cart item. Sending quantity=0 removes the item.',
        summary: 'Update cart item quantity',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateCartItemRequest'),
        ),
        tags: ['Cart'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/CartResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden (item not owned)',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Item not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function updateItem(UpdateCartItemRequest $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $cart = $this->service->getOrCreate($user);

        /** @var CartItem|null $item */
        $item = CartItem::query()->where('id', $id)->where('cart_id', $cart->id)->first();

        if ($item === null) {
            return ApiResponse::error(
                __('cart.item_not_found'),
                404,
                null,
                'ERR_NOT_FOUND',
            );
        }

        $this->service->updateQuantity($item, (int) $request->validated('quantity'));
        $cart->load('items.product.images');

        return ApiResponse::success(
            new CartResource($cart),
            __('cart.item_updated'),
        );
    }

    #[OA\Delete(
        path: '/api/v1/cart/items/{id}',
        operationId: 'cartRemoveItem',
        description: 'Remove a single item from the cart.',
        summary: 'Remove cart item',
        security: [['sanctum' => []]],
        tags: ['Cart'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true,
                schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/CartResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 404, description: 'Item not found',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function removeItem(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $cart = $this->service->getOrCreate($user);

        /** @var CartItem|null $item */
        $item = CartItem::query()->where('id', $id)->where('cart_id', $cart->id)->first();

        if ($item === null) {
            return ApiResponse::error(
                __('cart.item_not_found'),
                404,
                null,
                'ERR_NOT_FOUND',
            );
        }

        $this->service->removeItem($item);
        $cart->load('items.product.images');

        return ApiResponse::success(
            new CartResource($cart),
            __('cart.item_removed'),
        );
    }

    #[OA\Delete(
        path: '/api/v1/cart',
        operationId: 'cartClear',
        description: 'Empty the entire cart (remove all items).',
        summary: 'Clear cart',
        security: [['sanctum' => []]],
        tags: ['Cart'],
        responses: [
            new OA\Response(response: 200, description: 'OK',
                content: new OA\JsonContent(ref: '#/components/schemas/CartResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function clear(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $cart = $this->service->getOrCreate($user);

        $this->service->clear($cart);
        $cart->load('items.product.images');

        return ApiResponse::success(
            new CartResource($cart),
            __('cart.cleared'),
        );
    }
}
