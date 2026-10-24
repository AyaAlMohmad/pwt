<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\Auth\OtpLoginIssued;
use App\Events\Auth\OtpRegisterIssued;
use App\Events\Auth\PasswordResetEmail;
use App\Events\Auth\PasswordResetPhone;
use App\Events\Contact\ContactLeadCreated;
use App\Events\Rfq\RfqCreated;
use App\Events\Maintenance\MaintenanceRequestAssigned;
use App\Events\Maintenance\MaintenanceRequestCancelled;
use App\Events\Maintenance\MaintenanceRequestCompleted;
use App\Events\Maintenance\MaintenanceRequestCreated;
use App\Events\Maintenance\MaintenanceRequestInProgress;
use App\Events\Orders\OrderCancelled;
use App\Events\Orders\OrderCompleted;
use App\Events\Orders\OrderConfirmed;
use App\Events\Orders\OrderCreated;
use App\Events\Orders\OrderInstalled;
use App\Events\Orders\OrderOutForDelivery;
use App\Events\Orders\OrderScheduled;
use App\Events\Payments\PaymentCaptured;
use App\Events\Payments\PaymentFailed;
use App\Events\Rentals\MachineRentalPaymentCaptured;
use App\Events\Rentals\MachineRentalPaymentDue;
use App\Events\Rentals\MachineRentalPaymentFailed;
use App\Events\Support\SupportTicketAssigned;
use App\Events\Support\SupportTicketCancelled;
use App\Events\Support\SupportTicketClosed;
use App\Events\Support\SupportTicketCreated;
use App\Events\Support\SupportTicketReplied;
use App\Events\Support\SupportTicketResolved;
use App\Listeners\NotificationListener;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

/**
 * Phase 11 — explicit event/listener wiring.
 *
 * We deliberately list every event class rather than rely on Laravel
 * 11's auto-discovery so the cross-module fan-out is grep-able and the
 * test bootstrap is deterministic.
 *
 * See docs/architecture/notifications.md § 5.1.
 */
final class EventServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        // Orders
        OrderCreated::class => [NotificationListener::class],
        OrderConfirmed::class => [NotificationListener::class],
        OrderScheduled::class => [NotificationListener::class],
        OrderOutForDelivery::class => [NotificationListener::class],
        OrderInstalled::class => [NotificationListener::class],
        OrderCompleted::class => [NotificationListener::class],
        OrderCancelled::class => [NotificationListener::class],

        // Payments
        PaymentCaptured::class => [NotificationListener::class],
        PaymentFailed::class => [NotificationListener::class],

        // Rentals
        MachineRentalPaymentDue::class => [NotificationListener::class],
        MachineRentalPaymentCaptured::class => [NotificationListener::class],
        MachineRentalPaymentFailed::class => [NotificationListener::class],

        // Maintenance
        MaintenanceRequestCreated::class => [NotificationListener::class],
        MaintenanceRequestAssigned::class => [NotificationListener::class],
        MaintenanceRequestInProgress::class => [NotificationListener::class],
        MaintenanceRequestCompleted::class => [NotificationListener::class],
        MaintenanceRequestCancelled::class => [NotificationListener::class],

        // Support (Phase 12 — customer-facing)
        SupportTicketCreated::class => [NotificationListener::class],
        SupportTicketCancelled::class => [NotificationListener::class],

        // Support (Phase 19 — admin-facing). SupportTicketInProgress
        // is intentionally NOT registered: no template, internal only.
        SupportTicketAssigned::class => [NotificationListener::class],
        SupportTicketReplied::class => [NotificationListener::class],
        SupportTicketResolved::class => [NotificationListener::class],
        SupportTicketClosed::class => [NotificationListener::class],

        // Contact form (Phase 13 — guest, ops-only)
        ContactLeadCreated::class => [NotificationListener::class],

        // RFQ (Phase 22 — guest, ops-only)
        RfqCreated::class => [NotificationListener::class],

        // Auth — listener no-ops (orchestrator decision 2) but classes
        // are still wired so Phase 22 admin can preview template copy.
        OtpRegisterIssued::class => [NotificationListener::class],
        OtpLoginIssued::class => [NotificationListener::class],
        PasswordResetEmail::class => [NotificationListener::class],
        PasswordResetPhone::class => [NotificationListener::class],
    ];

    /**
     * Laravel 11: keep auto-discovery OFF — the explicit map above is
     * the single source of truth.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
