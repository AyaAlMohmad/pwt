<?php

declare(strict_types=1);

return [
    // Success messages
    'banners_retrieved' => 'Banners retrieved.',
    'promotions_retrieved' => 'Promotions retrieved.',
    'testimonials_retrieved' => 'Testimonials retrieved.',
    'validated' => 'Promotion validated.',

    // Validation messages
    'invalid_filters' => 'One or more filter values are invalid.',
    'code_required' => 'A promo code is required.',
    'subtotal_required' => 'The cart subtotal is required.',
    'subtotal_invalid' => 'The cart subtotal must be a non-negative number.',
    'currency_required' => 'A currency is required.',
    'currency_invalid' => 'The currency is not supported.',

    // /validate outcomes (valid=true)
    'reason' => [
        'applied' => 'Promo code applied — you saved :amount.',
        'code_unknown' => 'This code is not valid.',
        'not_started' => 'This code is not active yet.',
        'expired' => 'This code has expired.',
        'inactive' => 'This code is not available.',
        'min_order_not_met' => 'Add :amount more to your cart to use this code.',
        'currency_mismatch' => 'This code does not apply to the :currency currency.',
        'scope_mismatch' => 'This code does not apply to the items in your cart.',
        'per_user_limit_reached' => 'You have already used this code.',
        'total_limit_reached' => 'This code is no longer available.',
    ],

    'rate_limited' => 'Too many promo-code attempts. Please slow down.',
];
