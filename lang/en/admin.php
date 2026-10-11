<?php

declare(strict_types=1);

return [
    // Auth
    'login_success' => 'Welcome back.',
    'logout_success' => 'You have been logged out.',
    'me_ok' => 'OK',
    'inactive' => 'This admin account has been disabled.',

    // Users (Phase 15)
    'users' => [
        'created' => 'User created.',
        'updated' => 'User updated.',
        'deactivated' => 'User deactivated.',
        'cannot_deactivate_self' => 'You cannot deactivate your own account.',
        'cannot_change_own_role' => 'You cannot change your own role.',
        'last_super_admin' => 'You cannot remove the last active super admin.',
        'role_change_forbidden' => 'Only a super admin can change a user role.',
        'cannot_modify_super_admin' => 'You cannot modify another super admin.',
        'trashed_forbidden' => 'You are not authorised to view deactivated users.',
        'admin_email_required' => 'An admin must have an email address.',
        'phone_pair_required' => 'Phone number and country code must be provided together.',
        'empty_update_body' => 'At least one updatable field must be provided.',
    ],

    // Orders (Phase 17)
    'orders' => [
        'status_updated' => 'Order status updated.',
        'cancelled' => 'Order cancelled.',
        'installation_scheduled' => 'Installation scheduled.',
        'note_added' => 'Note added.',
        'retrieved' => 'Orders retrieved.',
        'note_required' => 'A note is required.',
        'note_min' => 'The note must be at least 3 characters.',
        'note_max_transition' => 'The transition note may not exceed 1000 characters.',
        'note_max_standalone' => 'The note may not exceed 2000 characters.',
        'reason_required' => 'A cancellation reason is required.',
        'reason_min' => 'The cancellation reason must be at least 5 characters.',
        'reason_max' => 'The cancellation reason may not exceed 1000 characters.',
        'status_required' => 'A status value is required.',
        'status_invalid' => 'The selected status is invalid.',
        'scheduled_for_at_required' => 'A scheduled date and time is required.',
        'scheduled_for_at_invalid' => 'The scheduled date and time must be a valid ISO-8601 timestamp.',
        'scheduled_for_at_future' => 'The scheduled date and time must be in the future.',
        'technician_invalid' => 'The selected technician is not an active operations admin.',
        'date_range_invalid' => 'The date range is invalid: date_to must be on or after date_from.',
        'country_unknown' => 'The selected country code is not recognised.',
        'period_invalid' => 'The selected period is invalid.',
        'trashed_forbidden' => 'You are not authorised to view trashed orders.',
        'forbidden_money_field' => 'Monetary fields are server-computed and may not be supplied here.',
        'notes_on_terminal' => 'Cannot add notes to a terminal order (:status).',
        'use_schedule_endpoint' => 'Use the schedule-installation endpoint to set scheduled_for_at and technician.',
        'schedule_requires_confirmed' => 'Only confirmed orders can be scheduled. Current status: :status.',
        'payment_not_captured' => 'Payment must be completed before changing the order status.',
    ],

    // Machines (Phase 18)
    'machines' => [
        'retrieved' => 'Machines retrieved.',
        'due_retrieved' => 'Machines due for maintenance retrieved.',
        'show_retrieved' => 'Machine retrieved.',
        'created' => 'Machine created.',
        'updated' => 'Machine updated.',
        'not_found' => 'Machine not found.',
        'duplicate_serial' => 'The serial number is already in use by another machine.',
        'address_not_owned' => 'The selected address does not belong to the supplied customer.',
        'user_not_customer' => 'The selected user is not an active customer account.',
        'product_inactive' => 'Cannot create a machine for an inactive product.',
        'resource_trashed' => 'Cannot modify a trashed machine.',
        'override_requires_interval' => 'The next maintenance due date can only be set when a filter change interval is configured.',
        'owner_change_requires_address' => 'Reassigning the machine to another customer requires an address_id owned by the new customer.',
        'trashed_forbidden' => 'You are not authorised to view trashed machines.',
        'forbidden_field' => 'This field is server-managed and may not be supplied here.',
    ],

    // Maintenance (Phase 18)
    'maintenance' => [
        'retrieved' => 'Maintenance requests retrieved.',
        'show_retrieved' => 'Maintenance request retrieved.',
        'created' => 'Maintenance request created on behalf of the customer.',
        'created_batch' => 'Maintenance requests created on behalf of the customer.',
        'assigned' => 'Maintenance request assigned.',
        'status_updated' => 'Maintenance request status updated.',
        'closed' => 'Maintenance request closed.',
        'not_found' => 'Maintenance request not found.',
        'technician_invalid' => 'The selected technician is not an active operations admin.',
        'user_not_customer' => 'The selected user is not an active customer account.',
        'machine_not_owned' => 'The selected machine does not belong to the supplied customer or is inactive.',
        'use_assign_endpoint' => 'Use the assign endpoint to set assigned_to_user_id.',
        'use_close_endpoint' => 'Use the close endpoint to provide resolution notes.',
        'only_in_progress_can_close' => 'Only in-progress requests can be closed.',
        'cannot_assign_in_state' => 'This request cannot be assigned in its current state.',
        'resource_trashed' => 'Cannot modify a trashed maintenance request.',
        'completed_at_invalid' => 'The completion date must be on or before today and on or after the machine installation date.',
        'forbidden_field' => 'This field is server-managed and may not be supplied here.',
        'trashed_forbidden' => 'You are not authorised to view trashed maintenance requests.',
        'no_machines' => 'No machines were selected for batch creation.',
    ],

    // Support (Phase 19)
    'support' => [
        'retrieved' => 'Support tickets retrieved.',
        'show_retrieved' => 'Support ticket retrieved.',
        'updated' => 'Support ticket updated.',
        'replied' => 'Reply posted.',
        'not_found' => 'Support ticket not found.',
        'trashed_forbidden' => 'You are not authorised to view trashed support tickets.',
        'trashed_modification' => 'Cannot modify a trashed support ticket.',
        'ticket_terminal' => 'Cannot reply to a closed or cancelled ticket. Use an internal note instead.',
        'invalid_assignee' => 'The selected user is not eligible for support assignment.',
        'empty_update_body' => 'At least one of status, priority, or assigned_to_user_id must be supplied.',
        'assignee_required_for_assigned' => "Assigned-to user id is required when status is 'assigned'.",
        'resolution_required' => 'A resolution summary is required when resolving a ticket.',
        'cancellation_reason_required' => 'A cancellation reason is required when cancelling a ticket.',
        'status_invalid' => 'The selected status is invalid.',
        'priority_invalid' => 'The selected priority is invalid.',
        'forbidden_field' => 'This field is server-managed and may not be supplied here.',
        'date_range_invalid' => 'The date range is invalid: date_to must be on or after date_from.',
        'reply_body_required' => 'A reply body is required.',
        'reply_body_min' => 'The reply must be at least 1 character.',
        'reply_body_max' => 'The reply may not exceed 5000 characters.',
        'attachments_max' => 'You may attach at most 5 files to a reply.',
        'attachments_invalid' => 'One or more attached files are invalid.',
    ],

    // Banners (Phase 20)
    'banners' => [
        'retrieved' => 'Banners retrieved.',
        'show_retrieved' => 'Banner retrieved.',
        'created' => 'Banner created.',
        'updated' => 'Banner updated.',
        'deleted' => 'Banner deleted.',
        'not_found' => 'Banner not found.',
        'date_range_invalid' => 'The end date must be after the start date.',
        'duplicate_code' => 'A banner with this code already exists.',
    ],

    // Promotions (Phase 20)
    'promotions' => [
        'retrieved' => 'Promotions retrieved.',
        'show_retrieved' => 'Promotion retrieved.',
        'created' => 'Promotion created.',
        'updated' => 'Promotion updated.',
        'deleted' => 'Promotion deleted.',
        'not_found' => 'Promotion not found.',
        'date_range_invalid' => 'The end date must be after the start date.',
        'duplicate_code' => 'A promotion with this code already exists.',
        'code_format' => 'The promotion code may only contain letters, numbers, dashes, and underscores.',
        'percent_over_hundred' => 'Percent discount cannot exceed 100.',
        'max_discount_only_for_percent' => 'The max discount amount is only applicable to percent-type promotions.',
        'has_redemptions' => 'Cannot delete a promotion that has been redeemed. Deactivate it instead.',
    ],

    // Testimonials (Phase 20)
    'testimonials' => [
        'retrieved' => 'Testimonials retrieved.',
        'show_retrieved' => 'Testimonial retrieved.',
        'created' => 'Testimonial created.',
        'updated' => 'Testimonial updated.',
        'deleted' => 'Testimonial deleted.',
        'not_found' => 'Testimonial not found.',
    ],

    // Reports (Phase 21)
    'reports' => [
        'sales_generated' => 'Sales report generated.',
        'orders_generated' => 'Orders report generated.',
        'maintenance_generated' => 'Maintenance report generated.',
        'customers_generated' => 'Customers report generated.',
        'forbidden' => 'You are not authorised to view this report.',
        'export_truncated' => 'Export truncated at :cap rows. Narrow the filters to retrieve the remaining :remaining rows.',
        'rate_limited' => 'Too many export requests. Please wait a minute before trying again.',
        'date_range_invalid' => 'The date range is invalid: date_to must be on or after date_from.',
        'window_too_wide' => 'The date range cannot exceed 366 days.',
        'country_unknown' => 'The selected country code is not recognised.',
        'product_unknown' => 'The selected product was not found.',
        'assignee_unknown' => 'The selected assignee was not found.',
        'status_invalid' => 'The selected status value is invalid.',
        'format_required' => 'An export format is required.',
        'format_invalid' => 'The export format must be either csv or xlsx.',
        'sales_cancelled_only_note' => 'Summary totals reflect cancelled orders only and therefore show zero revenue. Use a wider status filter to include active orders.',
    ],

    // Audit logs (Phase 22)
    'audit-logs' => [
        'retrieved' => 'Audit logs retrieved.',
        'date_range_invalid' => 'The date range is invalid: date_to must be on or after date_from.',
        'actor_unknown' => 'The selected actor was not found.',
    ],

    // Notification templates (Phase 22)
    'templates' => [
        'retrieved' => 'Notification templates retrieved.',
        'updated' => 'Notification template updated.',
        'not_found' => 'Notification template not found.',
        'subject_required' => 'A subject is required.',
        'subject_en_required' => 'The English subject is required.',
        'subject_ar_required' => 'The Arabic subject is required.',
        'body_required' => 'A body is required.',
        'body_en_required' => 'The English body is required.',
        'body_ar_required' => 'The Arabic body is required.',
        'is_active_required' => 'The is_active flag is required.',
    ],

    // Dashboard (Phase 22)
    'dashboard' => [
        'retrieved' => 'Dashboard snapshot retrieved.',
    ],

    // Payment Settings (Phase 22)
    'payment_settings' => [
        'retrieved' => 'Payment settings retrieved.',
        'updated' => 'Payment settings updated.',
    ],

    // Mail Settings (Phase 22)
    'mail_settings' => [
        'retrieved' => 'Mail settings retrieved.',
        'updated' => 'Mail settings updated.',
    ],

    // Catalog (Phase 16)
    'catalog' => [
        'category_created' => 'Category created.',
        'category_updated' => 'Category updated.',
        'category_deleted' => 'Category deleted.',
        'category_has_products' => 'Cannot delete a category that still contains products. Please reassign or trash the products first.',
        'product_created' => 'Product created.',
        'product_updated' => 'Product updated.',
        'product_deleted' => 'Product deleted.',
        'product_status_updated' => 'Product status updated.',
        'product_has_active_orders' => 'Cannot delete a product that is referenced by active orders. Cancel or complete the orders first.',
        'product_code_immutable' => 'The product code cannot be changed after creation.',
        'image_attached' => 'Image attached.',
        'image_detached' => 'Image removed.',
        'image_invalid_file_type' => 'The selected file is not a product image.',
        'image_cross_product' => 'The selected image does not belong to this product.',
    ],
];
