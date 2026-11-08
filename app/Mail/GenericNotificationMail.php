<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Generic notification email — renders a single
 * {@see Notification} row into a minimal HTML body.
 *
 * Branding / templated HTML scaffolding is intentionally deferred to
 * Phase 22 when the admin template editor lands; the Phase 11 MVP
 * renders the template body inline with optional action-URL CTA.
 *
 * Always queued (ShouldQueue) so tests can assert via
 * `Mail::fake() + Mail::assertQueued(GenericNotificationMail::class)`.
 */
final class GenericNotificationMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly Notification $notification) {}

    public function envelope(): Envelope
    {
        $locale = app()->getLocale();
        $title = (string) $this->notification->getTranslation('title', $locale);

        return new Envelope(subject: $title !== '' ? $title : __('notifications.default_subject'));
    }

    public function content(): Content
    {
        $locale = app()->getLocale();
        $title = (string) $this->notification->getTranslation('title', $locale);
        $body = (string) $this->notification->getTranslation('body', $locale);

        if ($this->notification->type === 'rental.payment_failed') {
            $data = $this->notification->data ?? [];

            return new Content(
                view: 'emails.rental-payment-failed',
                with: [
                    'details' => [
                        'customer_name' => ($data['customer_name'] ?? '') ?: ($this->notification->user?->name ?? 'Customer'),
                        'machine_name' => $data['machine_name'] ?? 'PWT water dispenser',
                        'machine_id' => $data['machine_id'] ?? '—',
                        'amount' => $data['amount'] ?? 0,
                        'currency' => $data['currency'] ?? 'AED',
                        'failure_reason' => ! empty($data['customer_reason']) ? $data['customer_reason'] : 'We could not process your payment. Please review your payment method.',
                        'payment_url' => $this->notification->action_url,
                    ],
                ],
            );
        }

        return new Content(
            view: 'emails.notification',
            with: [
                'title' => $title,
                'body' => $body,
                'actionUrl' => $this->notification->action_url,
                'actionText' => __('notifications.cta'),
            ],
        );
    }

    /**
     * @return array<int, mixed>
     */
    public function attachments(): array
    {
        return [];
    }
}
