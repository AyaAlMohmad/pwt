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
 * Password-reset email.
 *
 * Carries a 6-digit OTP code for mobile-app flows. Rendering is deferred
 * until Phase 11 (notification templates); for now the message body is
 * inline. Always queued — callers use `Mail::queue()` and tests use
 * `Mail::fake()`.
 */
final class PasswordResetMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $email,
        public readonly string $otpCode,
        public readonly int $ttlMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('auth.reset_email_subject'),
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<p>'
                .__('auth.reset_email_otp_body', [
                    'code' => $this->otpCode,
                    'minutes' => (string) $this->ttlMinutes,
                ])
                .'</p>',
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
