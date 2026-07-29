<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Maximum addresses per customer
    |--------------------------------------------------------------------------
    |
    | Soft cap enforced by the AddressService at create time. The 11th
    | POST /me/addresses is rejected with 422 ERR_ADDRESS_LIMIT_REACHED.
    | Configurable via ADDRESSES_MAX_PER_USER so the cap can be bumped
    | without a code change if real-world usage demands it.
    |
    | Per docs/architecture/addresses.md § 9.1 the default is 10.
    */

    'max_per_user' => (int) env('ADDRESSES_MAX_PER_USER', 10),

];
