<?php

declare(strict_types=1);

return [
    // Success messages
    'order_placed' => 'Order placed',
    'order_replayed' => 'Order already placed',
    'orders_retrieved' => 'Orders retrieved',
    'order_retrieved' => 'Order retrieved',
    'cancel_succeeded' => 'Order cancelled',
    'cancel_with_refund_note' => 'Order cancelled. Our team will contact you about the refund process.',
    'tracking_retrieved' => 'Tracking retrieved',
    'dashboard_retrieved' => 'Dashboard retrieved',

    // Validation messages
    'items_required' => 'At least one item is required.',
    'items_max' => 'A maximum of :max items per order is allowed.',
    'product_id_required' => 'A product id is required for each line.',
    'quantity_min' => 'Quantity must be at least 1.',
    'quantity_max' => 'Quantity may not exceed 50 per line.',
    'term_invalid' => 'The selected term is invalid.',
    'delivery_address_required' => 'A delivery address is required.',
    'delivery_date_required' => 'Delivery date is required.',
    'delivery_date_invalid' => 'Delivery date must be today or later.',
    'delivery_time_slot_required' => 'Delivery time slot is required.',
    'delivery_time_slot_invalid' => 'The selected time slot is invalid.',
    'customer_name_required' => 'Full name is required.',
    'customer_email_required' => 'Email address is required.',
    'customer_phone_required' => 'Phone number is required.',
    'card_number_required' => 'Card number is required for credit/debit card payment.',
    'card_expiry_required' => 'Card expiry is required for credit/debit card payment.',
    'card_cvc_required' => 'CVC is required for credit/debit card payment.',
    'address_not_owned' => 'The selected address is not available.',
    'payment_method_invalid' => 'The selected payment method is invalid.',
    'notes_max' => 'Notes may not exceed 500 characters.',
    'field_forbidden' => 'This field is not allowed; the server recomputes pricing.',
    'idempotency_key_invalid' => 'Idempotency-Key must be 8-80 characters of letters, digits, hyphens, or underscores.',
    'cancel_reason_required' => 'A cancellation reason is required.',
    'cancel_reason_min' => 'The cancellation reason must be at least 5 characters.',
    'cancel_reason_max' => 'The cancellation reason may not exceed 500 characters.',

    // Cart / catalog availability messages
    'product_unavailable' => 'One or more products in your cart are unavailable.',
    'product_not_found' => 'Product not found.',
    'product_inactive' => 'This product is not currently available.',
    'product_out_of_stock' => 'Product is out of stock.',
    'product_quote_only' => 'This product requires a quote — please contact sales.',
    'price_not_available' => 'A price for this product is not available in :currency.',

    // Promo errors
    'promo_invalid' => 'The promo code could not be applied.',

    // State machine
    'invalid_status_transition' => 'Cannot move order from :from to :to.',
    'cancel_not_allowed' => 'This order can no longer be cancelled. Please contact support.',
    'cancel_already_cancelled' => 'This order has already been cancelled.',

    // Rate limit
    'rate_limited' => 'Too many requests. Please slow down.',

    // Milestones
    'milestone' => [
        'quotation_approved' => 'Quotation approved',
        'deposit_invoiced' => 'Deposit invoiced',
        'in_production' => 'In production',
        'phased_delivery' => 'Phased delivery',
        'installation_training' => 'Installation & staff training',
    ],

    // Tracking next-step labels
    'next_step' => [
        'pending_cod' => 'Awaiting confirmation by our team',
        'pending_online' => 'Awaiting payment confirmation',
        'confirmed' => 'Awaiting delivery scheduling',
        'scheduled' => 'Awaiting dispatch',
        'out_for_delivery' => 'Out for delivery — please be available at your address',
        'installed' => 'Awaiting completion sign-off',
    ],
];
