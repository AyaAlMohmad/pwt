<?php

declare(strict_types=1);

return [
    // Success messages
    'request_submitted' => 'Maintenance request submitted.',
    'requests_submitted' => 'Maintenance requests submitted.',
    'requests_retrieved' => 'Maintenance requests retrieved.',
    'request_retrieved' => 'Maintenance request retrieved.',
    'request_cancelled' => 'Maintenance request cancelled.',
    'request_rescheduled' => 'Maintenance visit rescheduled.',
    'machines_retrieved' => 'Machines retrieved.',
    'machine_retrieved' => 'Machine retrieved.',

    // Validation
    'machine_not_owned' => 'This machine does not belong to you or is not active.',
    'file_not_owned' => 'One or more attached files are not available.',
    'description_required' => 'A description of the issue is required.',
    'description_min' => 'Please provide a more detailed description (at least 10 characters).',
    'description_max' => 'The description may not exceed 2000 characters.',
    'issue_type_invalid' => 'The selected issue type is invalid.',
    'preferred_date_invalid' => 'The preferred date must be a future date within 90 days.',
    'media_max' => 'You may attach at most 5 photos to a maintenance request.',
    'media_invalid' => 'One or more attached files are invalid.',
    'cancel_reason_required' => 'A cancellation reason is required.',
    'cancel_reason_min' => 'The cancellation reason must be at least 5 characters.',
    'cancel_reason_max' => 'The cancellation reason may not exceed 500 characters.',
    'preferred_date_required' => 'A preferred date is required.',
    'preferred_time_invalid' => 'The preferred time is too long.',
    'reschedule_not_allowed' => 'This maintenance request can no longer be rescheduled.',

    // State machine
    'invalid_status_transition' => 'Cannot move maintenance request from :from to :to.',
    'cancel_not_allowed' => 'This maintenance request can no longer be cancelled.',
    'already_completed' => 'This request is already completed.',
    'already_cancelled' => 'This request is already cancelled.',

    // Bilingual labels — sentence case
    'issue_type' => [
        'filter_change' => 'Filter change',
        'leak' => 'Leak',
        'noise' => 'Noise',
        'low_pressure' => 'Low water pressure',
        'water_quality' => 'Water quality',
        'error_code' => 'Error code',
        'installation_fix' => 'Installation fix',
        'other' => 'Other',
    ],
    'priority' => [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'urgent' => 'Urgent',
    ],
    'status' => [
        'new' => 'New',
        'assigned' => 'Assigned',
        'in_progress' => 'In progress',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ],
];
