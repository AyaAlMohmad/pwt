<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\Enums\ProductTerm;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Customer shopping-cart orchestration.
 *
 * One cart per user. Adding a product that already exists for the same
 * term increments quantity. Mixing terms (buy vs rent) creates separate
 * line items.
 */
final class CartService
{
    /**
     * Get or create the caller's cart, eager-loaded with items + products.
     */
    public function getOrCreate(User $user): Cart
    {
        /** @var Cart|null $cart */
        $cart = Cart::query()
            ->with('items.product.images')
            ->forUser((int) $user->id)
            ->first();

        if ($cart !== null) {
            return $cart;
        }

        return Cart::query()->create([
            'user_id' => $user->id,
            'currency' => 'AED',
        ]);
    }

    /**
     * Add a product to the cart.
     *
     * @param  array{product_id: int, term: string, quantity: positive-int}  $item
     */
    public function addItem(Cart $cart, array $item): CartItem
    {
        $productId = (int) $item['product_id'];
        $term = $item['term'];
        $quantity = (int) $item['quantity'];

        $existing = CartItem::query()
            ->where('cart_id', $cart->id)
            ->where('product_id', $productId)
            ->where('term', $term)
            ->first();

        if ($existing instanceof CartItem) {
            $existing->quantity += $quantity;
            $existing->save();

            return $existing;
        }

        return CartItem::query()->create([
            'cart_id' => $cart->id,
            'product_id' => $productId,
            'term' => $term,
            'quantity' => $quantity,
        ]);
    }

    /**
     * Update the quantity of a specific cart item.
     */
    public function updateQuantity(CartItem $item, int $quantity): void
    {
        if ($quantity <= 0) {
            $item->delete();

            return;
        }

        $item->quantity = $quantity;
        $item->save();
    }

    /**
     * Remove a single item from the cart.
     */
    public function removeItem(CartItem $item): void
    {
        $item->delete();
    }

    /**
     * Empty the entire cart.
     */
    public function clear(Cart $cart): void
    {
        $cart->items()->delete();
    }

    /**
     * Delete the cart itself (and cascade its items).
     */
    public function deleteCart(Cart $cart): void
    {
        $cart->delete();
    }
}
