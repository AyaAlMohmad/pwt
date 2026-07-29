<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Minimal ops-side mailable used for Phase 12 support ticket events.
 *
 * Unlike {@see GenericNotificationMail}, this mailable is NOT backed by a
 * persisted `notifications` row — the ops inbox is an address, not a
 * user. Title + body are rendered against the bilingual support
 * template (EN copy only — ops staff operate in English per the BRD).
 *
 * Always queued so test bootstrap can assert
 * `Mail::assertQueued(GenericOpsNotificationMail::class)`.
 */
final class GenericOpsNotificationMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $title,
        public readonly string $body,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->title !== '' ? $this->title : __('notifications.default_subject'),
        );
    }

    public function content(): Content
    {
        $html = '<p>'.e($this->body).'</p>';

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
