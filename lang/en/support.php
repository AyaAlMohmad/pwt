<?php

declare(strict_types=1);

return [
    // Success messages
    'ticket_created' => 'Support ticket created.',
    'tickets_retrieved' => 'Support tickets retrieved.',
    'ticket_retrieved' => 'Support ticket retrieved.',
    'ticket_cancelled' => 'Support ticket cancelled.',

    // Validation
    'subject_required' => 'A subject is required.',
    'subject_min' => 'The subject must be at least 3 characters.',
    'subject_max' => 'The subject may not exceed 200 characters.',
    'category_required' => 'Please select a category.',
    'category_invalid' => 'The selected category is invalid.',
    'message_required' => 'A message describing the issue is required.',
    'message_min' => 'Please describe the issue in at least 10 characters.',
    'message_max' => 'The message may not exceed 5000 characters.',
    'related_order_not_owned' => 'The selected related order is not yours.',
    'related_machine_not_owned' => 'The selected related machine is not yours.',
    'file_not_owned' => 'The selected attachment is not yours or has already been used.',
    'attachments_max' => 'You may attach at most 5 files to a support ticket.',
    'attachments_invalid' => 'One or more attached files are invalid.',
    'cancel_reason_max' => 'The cancellation reason may not exceed 500 characters.',
    'field_forbidden' => 'This field is not allowed.',

    // State machine
    'invalid_status_transition' => 'Cannot move support ticket from :from to :to.',
    'cancel_not_allowed' => 'This ticket can no longer be cancelled.',
    'already_cancelled' => 'This ticket has already been cancelled.',
    'already_closed' => 'This ticket has been closed.',
    'already_resolved' => 'This ticket has already been resolved.',

    // Rate limiting
    'rate_limited' => 'Too many ticket submissions. Please try again later.',

    // Anonymisation fallback
    'system_sender' => 'System',
    'support_fallback_name' => 'Support',

    // Bilingual labels — sentence case
    'category' => [
        'order_issue' => 'Order issue',
        'payment_issue' => 'Payment issue',
        'maintenance_issue' => 'Maintenance issue',
        'product_question' => 'Product question',
        'account_issue' => 'Account issue',
        'feedback' => 'Feedback',
        'other' => 'Other',
    ],
    'priority' => [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'urgent' => 'Urgent',
    ],
    'status' => [
        'open' => 'Open',
        'assigned' => 'Assigned',
        'in_progress' => 'In progress',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
        'cancelled' => 'Cancelled',
    ],
];
