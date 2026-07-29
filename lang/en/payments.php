<?php

declare(strict_types=1);

return [
    // Success messages
    'initiated' => 'Payment initiated',
    'session_created' => 'Payment session created',
    'replay_session' => 'Payment session already created',
    'cod_confirmed' => 'Cash on Delivery confirmed. Order is awaiting confirmation.',
    'replay_initiated' => 'Payment already initiated',
    'replay_cod' => 'Cash on Delivery already confirmed',
    'status_retrieved' => 'Payment status retrieved',
    'webhook_accepted' => 'Webhook accepted',
    'webhook_replayed' => 'Payment event already processed',

    // Error messages
    'signature_invalid' => 'Invalid signature.',
    'not_payable' => 'This order is not in a payable state.',
    'not_payable_with_status' => "Order is in ':status' state and cannot be paid.",
    'already_initiated' => 'An active payment is already in progress for this order.',
    'method_mismatch' => 'The payment method does not match the order.',
    'invalid_state_transition' => 'Cannot move payment from :from to :to.',
    'order_not_owned' => 'The selected order is not available.',
    'rate_limited' => 'Too many payment attempts. Please try again later.',
    'gateway_unavailable' => 'Payment gateway is temporarily unavailable. Please try again.',
    'forbidden' => 'This payment belongs to another user.',
];
