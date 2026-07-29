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
        $body = (string) $this->notification->getTranslation('body', $locale);

        $html = '<p>'.e($body).'</p>';
        if ($this->notification->action_url !== null && $this->notification->action_url !== '') {
            $html .= '<p><a href="'.e($this->notification->action_url).'">'.e(__('notifications.cta')).'</a></p>';
        }

        return new Content(htmlString: $html);
    }

    /**
     * @return array<int, mixed>
     */
    public function attachments(): array
    {
        return [];
    }
}
