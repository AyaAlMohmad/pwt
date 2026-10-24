<?php

declare(strict_types=1);

return [
    'list_retrieved' => 'Notifications retrieved.',
    'marked_read' => 'Notification marked as read.',
    'all_marked_read' => 'All notifications marked as read.',
    'cta' => 'View details',
    'default_subject' => 'You have a new notification from PWT.',

    'validation' => [
        'type_format' => 'The type filter must look like `domain.event_name` (e.g. order.confirmed).',
        'date_range' => 'date_to must be on or after date_from.',
    ],

    // Customer-facing template copy (seeded). Kept here so admin Phase 22
    // can later show "reset to default" against the seeded values.
    'templates' => [
        'order' => [
            'created' => [
                'subject' => 'Order received',
                'body' => 'Thanks for your order #{{order.public_id}}. We will confirm it shortly.',
            ],
            'confirmed' => [
                'subject' => 'Your order is confirmed',
                'body' => 'Order #{{order.public_id}} has been confirmed.',
            ],
            'scheduled' => [
                'subject' => 'Your installation is scheduled',
                'body' => 'Order #{{order.public_id}} is scheduled for installation.',
            ],
            'out_for_delivery' => [
                'subject' => 'Out for delivery',
                'body' => 'Order #{{order.public_id}} is out for delivery today.',
            ],
            'installed' => [
                'subject' => 'Installation complete',
                'body' => 'Order #{{order.public_id}} has been installed. Enjoy your PWT machine!',
            ],
            'cancelled' => [
                'subject' => 'Order cancelled',
                'body' => 'Order #{{order.public_id}} has been cancelled.',
            ],
        ],
        'payment' => [
            'captured' => [
                'subject' => 'Payment received',
                'body' => 'We received your payment for order #{{order.id}}. Thank you!',
            ],
            'failed' => [
                'subject' => 'Payment failed',
                'body' => 'Your payment for order #{{order.id}} could not be completed. Please try again.',
            ],
        ],
        'maintenance' => [
            'created' => [
                'subject' => 'Maintenance request received',
                'body' => 'We received maintenance request #{{maintenance.public_id}}. Our team will respond shortly.',
            ],
            'assigned' => [
                'subject' => 'Technician assigned',
                'body' => 'A technician has been assigned to your maintenance request #{{maintenance.public_id}}.',
            ],
            'in_progress' => [
                'subject' => 'Maintenance in progress',
                'body' => 'Your maintenance request #{{maintenance.public_id}} is now in progress.',
            ],
            'completed' => [
                'subject' => 'Maintenance completed',
                'body' => 'Maintenance request #{{maintenance.public_id}} has been completed.',
            ],
            'cancelled' => [
                'subject' => 'Maintenance request cancelled',
                'body' => 'Maintenance request #{{maintenance.public_id}} has been cancelled.',
            ],
        ],
        'support' => [
            'created' => [
                'subject' => 'We received your support ticket',
                'body' => 'Thank you — your support ticket has been created. Our team will respond shortly.',
            ],
            'created_ops' => [
                'subject' => 'New support ticket filed',
                'body' => 'A new support ticket has been filed by {{user.name}}. Please assign within SLA.',
            ],
            'cancelled' => [
                'subject' => 'Support ticket cancelled',
                'body' => 'Your support ticket has been cancelled.',
            ],
            'cancelled_ops' => [
                'subject' => 'Support ticket cancelled by customer',
                'body' => 'A customer has cancelled their support ticket. No action required.',
            ],
            'assigned' => [
                'subject' => 'Your support ticket has been assigned',
                'body' => 'Your support ticket has been assigned to one of our agents. They will reach out shortly.',
            ],
            'assigned_ops' => [
                'subject' => 'You have a new support ticket assignment',
                'body' => 'A support ticket (#{{data.support_ticket_id}}) has been assigned to you.',
            ],
            'replied' => [
                'subject' => 'New reply on your support ticket',
                'body' => 'Our team has replied to your support ticket. Open the app to view the message.',
            ],
            'resolved' => [
                'subject' => 'Your support ticket has been resolved',
                'body' => 'Your support ticket has been marked as resolved. Please let us know if you need further help.',
            ],
            'closed' => [
                'subject' => 'Your support ticket has been closed',
                'body' => 'Your support ticket has been closed. Thank you for contacting us.',
            ],
        ],
        'contact' => [
            'created_ops' => [
                'subject' => 'New contact form submission',
                'body' => 'A new contact form submission has been received.'."\n\n"
                        .'Name: {{lead.name}}'."\n"
                        .'Email: {{lead.email}}'."\n"
                        .'Phone: {{lead.phone}}'."\n"
                        .'Source: {{lead.source}}'."\n"
                        .'Subject: {{lead.subject}}'."\n\n"
                        .'{{lead.message}}',
            ],
        ],
        'auth' => [
            'otp_register' => [
                'subject' => 'Registration code',
                'body' => 'Your PWT registration code was sent by SMS.',
            ],
            'otp_login' => [
                'subject' => 'Login code',
                'body' => 'Your PWT login code was sent by SMS.',
            ],
            'password_reset_email' => [
                'subject' => 'Password reset link sent',
                'body' => 'A password reset link was sent to your email.',
            ],
            'password_reset_phone' => [
                'subject' => 'Password reset code sent',
                'body' => 'A password reset code was sent to your phone.',
            ],
        ],
        'rental' => [
            'payment_due' => [
                'subject' => 'Upcoming rental payment reminder',
                'body' => 'Dear {{data.user_name}}, your next rental payment for machine {{data.machine_name}} is due on {{data.due_date}}. Please ensure your saved card has sufficient balance for the automatic deduction.',
            ],
            'payment_captured' => [
                'subject' => 'Rental payment successful',
                'body' => 'Dear {{data.user_name}}, your automatic rental payment of {{data.currency}} {{data.amount}} for machine {{data.machine_name}} was successful.',
            ],
            'payment_failed' => [
                'subject' => 'Rental payment failed',
                'body' => 'Dear {{data.user_name}}, your automatic rental payment of {{data.currency}} {{data.amount}} for machine {{data.machine_name}} could not be processed. Reason: {{data.reason}}. Please update your payment method.',
            ],
            'payment_due_admin' => [
                'subject' => 'Rental payment approaching',
                'body' => 'The rented machine {{data.machine_name}} for customer {{data.user_name}} ({{data.user_email}}) has a payment due on {{data.due_date}}.',
            ],
            'payment_captured_admin' => [
                'subject' => 'Rental payment successful',
                'body' => 'Automatic rental payment of {{data.currency}} {{data.amount}} for machine {{data.machine_name}} (customer: {{data.user_name}} / {{data.user_email}}) was successful.',
            ],
            'payment_failed_admin' => [
                'subject' => 'Rental payment failed',
                'body' => 'Automatic rental payment of {{data.currency}} {{data.amount}} for machine {{data.machine_name}} (customer: {{data.user_name}} / {{data.user_email}}) failed. Reason: {{data.reason}}.',
            ],
        ],
    ],
];
