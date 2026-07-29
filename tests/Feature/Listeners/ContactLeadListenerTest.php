<?php

declare(strict_types=1);

use App\Events\Contact\ContactLeadCreated;
use App\Mail\GenericOpsNotificationMail;
use App\Models\ContactLead;
use Database\Seeders\NotificationTemplatesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| NotificationListener — Phase 13 ContactLeadCreated fan-out
|--------------------------------------------------------------------------
|
| Contract: docs/architecture/contact.md § 9.3.
|   - Re-queries the contact_leads row.
|   - Renders the `contact.created_ops` template (email channel).
|   - Queues exactly one GenericOpsNotificationMail to the configured ops
|     inbox. Failures (missing template / missing row) are logged and
|     swallowed — never crash the listener.
*/

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(NotificationTemplatesSeeder::class);

    Mail::fake();
    config(['notifications.duplicate_window_seconds' => 0]);
    config(['notifications.ops_inbox_email' => 'ops@pwt.example']);
});

describe('NotificationListener — ContactLeadCreated', function (): void {
    it('queues a GenericOpsNotificationMail to the configured ops inbox', function (): void {
        $lead = ContactLead::factory()->create([
            'name' => 'Customer Lead',
            'email' => 'lead@example.com',
            'subject' => 'I need help with my new install',
            'message' => 'Please call me back to discuss the install schedule.',
        ]);

        event(new ContactLeadCreated(contactLeadId: (int) $lead->id, actorUserId: null));

        Mail::assertQueued(
            GenericOpsNotificationMail::class,
            fn (GenericOpsNotificationMail $mail): bool => $mail->hasTo('ops@pwt.example'),
        );
        Mail::assertQueuedCount(1);
    });

    it('renders the ops email subject from the contact.created_ops template', function (): void {
        $lead = ContactLead::factory()->create([
            'subject' => 'Subject-line probe',
        ]);

        event(new ContactLeadCreated(contactLeadId: (int) $lead->id, actorUserId: null));

        Mail::assertQueued(
            GenericOpsNotificationMail::class,
            function (GenericOpsNotificationMail $mail): bool {
                // The seeded `contact.created_ops` template subject reads
                // "New contact form submission" — confirms the renderer
                // dipped into the right template row.
                return str_contains($mail->title, 'New contact form submission');
            },
        );
    });

    it('does NOT queue an ops mail when ops_inbox_email is blank', function (): void {
        config(['notifications.ops_inbox_email' => '']);

        $lead = ContactLead::factory()->create();

        event(new ContactLeadCreated(contactLeadId: (int) $lead->id, actorUserId: null));

        Mail::assertNotQueued(GenericOpsNotificationMail::class);
    });

    it('handles a missing (non-persisted) lead gracefully without crashing or queueing mail', function (): void {
        // Defensive: the honeypot path returns a phantom (non-persisted)
        // model that does NOT fire the event — but if some upstream caller
        // dispatched the event for a stale / since-deleted row, the
        // listener must log + swallow rather than throw.
        event(new ContactLeadCreated(contactLeadId: 999_999_999, actorUserId: null));

        Mail::assertNotQueued(GenericOpsNotificationMail::class);
        // No exception bubbled out — test reaches this point cleanly.
        expect(true)->toBeTrue();
    });
});
