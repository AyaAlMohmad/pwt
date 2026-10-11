<?php

declare(strict_types=1);

use App\Services\Notifications\Channels\Drivers\EmailChannel;
use App\Services\Notifications\Channels\Drivers\InAppChannel;
use App\Services\Notifications\Channels\Drivers\PushChannel;
use App\Services\Notifications\Channels\Drivers\SmsChannel;
use App\Services\Notifications\Channels\Drivers\WhatsAppChannel;

/*
|--------------------------------------------------------------------------
| Notifications configuration (Phase 11)
|--------------------------------------------------------------------------
|
| Wiring for the cross-cutting notification dispatch infrastructure.
| Channel-driver bindings, event-type → channel routing, and the
| duplicate-suppression window all live here so the dispatcher remains
| boring & test-friendly.
|
| See docs/architecture/notifications.md § 5.6.
*/

return [

    /*
     * Queue name used for SendNotificationJob. The default `notifications`
     * queue keeps notification traffic off the catch-all worker so a slow
     * SMS gateway never starves order / payment background work.
     */
    'queue' => env('NOTIFICATIONS_QUEUE', 'notifications'),

    /*
     * Single shared ops inbox used by the maintenance-created-for-ops
     * notification. Phase 22 may add per-region routing.
     */
    'ops_inbox_email' => env('OPS_INBOX_EMAIL', 'info@pwtmegatech.com'),

    /*
     * Channel-driver bindings. Keyed by the enum value to match
     * `notifications.channel` / `notification_templates.channel`.
     */
    'channels' => [
        'in_app' => InAppChannel::class,
        'email' => EmailChannel::class,
        'sms' => SmsChannel::class,
        'whatsapp' => WhatsAppChannel::class,
        'push' => PushChannel::class,
    ],

    /*
     * Event type → list of channels to fan out to. Per orchestrator
     * decision 2, the four auth.* keys persist an in_app audit row only
     * (no second email / SMS). The seeded `notification_templates` rows
     * carry the copy for each (type, channel) combination wired here.
     */
    'channels_per_type' => [
        'auth.otp_register' => ['in_app'],
        'auth.otp_login' => ['in_app'],
        'auth.password_reset_email' => ['in_app'],
        'auth.password_reset_phone' => ['in_app'],
        'order.created' => ['in_app', 'email'],
        'order.confirmed' => ['in_app', 'sms', 'email'],
        'order.scheduled' => ['in_app', 'sms'],
        'order.out_for_delivery' => ['in_app', 'sms'],
        'order.installed' => ['in_app', 'email'],
        'order.cancelled' => ['in_app', 'email'],
        'payment.captured' => ['in_app', 'email'],
        'payment.failed' => ['in_app', 'email'],
        'maintenance.created' => ['in_app'],
        'maintenance.assigned' => ['in_app', 'sms'],
        'maintenance.in_progress' => ['in_app'],
        'maintenance.completed' => ['in_app', 'email'],
        'maintenance.cancelled' => ['in_app'],
        'support.created' => ['in_app', 'email'],
        'support.created_ops' => ['email'],
        'support.cancelled' => ['in_app'],
        'support.cancelled_ops' => ['email'],
        // Phase 19 — admin-driven support events.
        'support.assigned' => ['in_app', 'email'],
        'support.assigned_ops' => ['email'],
        'support.replied' => ['in_app', 'email'],
        'support.resolved' => ['in_app', 'email'],
        'support.closed' => ['in_app'],
        // Phase 13 — guest contact form. Ops-only (no user_id, no
        // in_app fan-out). See docs/architecture/contact.md § 9.4.
        'contact.created_ops' => ['email'],
    ],

    /*
     * Duplicate-suppression window. Within this window, the same
     * (user_id, type, primary_business_id) tuple is skipped at dispatch
     * time so COD checkout (which fires order.created + order.confirmed
     * back-to-back) does not double-notify on the same business surface.
     *
     * Set to 0 in test bootstrap to avoid skips during fast-running tests.
     */
    'duplicate_window_seconds' => (int) env('NOTIFICATIONS_DUP_WINDOW', 30),

];
