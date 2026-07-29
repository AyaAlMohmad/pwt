<?php

declare(strict_types=1);

use App\Events\Support\SupportTicketCancelled;
use App\Events\Support\SupportTicketCreated;
use App\Mail\GenericOpsNotificationMail;
use App\Models\Notification;
use App\Models\User;
use Database\Seeders\NotificationTemplatesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| NotificationListener — support.* fan-out
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/support.md § 12.1 + § 12.3 +
| docs/architecture/notifications.md § 3 / § 5.1.
| SupportTicketCreated  → in_app + email (customer) + email (ops inbox).
| SupportTicketCancelled → in_app (customer) + email (ops inbox).
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(NotificationTemplatesSeeder::class);

    Mail::fake();
    config(['notifications.duplicate_window_seconds' => 0]);
    config(['notifications.ops_inbox_email' => 'ops@pwt.example']);
});

describe('SupportTicketCreated listener fan-out', function (): void {
    it('persists in_app + email rows for the customer', function (): void {
        $user = User::factory()->customer()->create();

        event(new SupportTicketCreated(
            supportTicketId: 4242,
            userId: (int) $user->id,
            actorUserId: (int) $user->id,
        ));

        $rows = Notification::query()
            ->where('user_id', $user->id)
            ->where('type', 'support.created')
            ->get();

        expect($rows->pluck('channel')->map(fn ($c) => $c->value)->sort()->values()->all())
            ->toBe(['email', 'in_app']);

        foreach ($rows as $row) {
            expect($row->title)->not->toBe('');
        }
    });

    it('queues a GenericOpsNotificationMail to the configured ops inbox', function (): void {
        $user = User::factory()->customer()->create();

        event(new SupportTicketCreated(
            supportTicketId: 4242,
            userId: (int) $user->id,
            actorUserId: (int) $user->id,
        ));

        Mail::assertQueued(
            GenericOpsNotificationMail::class,
            fn (GenericOpsNotificationMail $mail): bool => $mail->hasTo('ops@pwt.example'),
        );
    });

    it('does NOT queue an ops mail when ops_inbox_email is blank', function (): void {
        config(['notifications.ops_inbox_email' => '']);

        $user = User::factory()->customer()->create();

        event(new SupportTicketCreated(
            supportTicketId: 4242,
            userId: (int) $user->id,
            actorUserId: (int) $user->id,
        ));

        // The customer channels (email + in_app) still fire — only the
        // ops-side GenericOpsNotificationMail must be suppressed when the
        // ops inbox address is empty.
        Mail::assertNotQueued(GenericOpsNotificationMail::class);
    });
});

describe('SupportTicketCancelled listener fan-out', function (): void {
    it('persists in_app for the customer and queues ops email', function (): void {
        $user = User::factory()->customer()->create();

        event(new SupportTicketCancelled(
            supportTicketId: 7,
            userId: (int) $user->id,
            reason: 'No longer needed',
            actorUserId: (int) $user->id,
        ));

        $rows = Notification::query()
            ->where('user_id', $user->id)
            ->where('type', 'support.cancelled')
            ->get();

        expect($rows->pluck('channel')->map(fn ($c) => $c->value)->sort()->values()->all())
            ->toBe(['in_app']);

        Mail::assertQueued(
            GenericOpsNotificationMail::class,
            fn (GenericOpsNotificationMail $mail): bool => $mail->hasTo('ops@pwt.example'),
        );
    });
});
