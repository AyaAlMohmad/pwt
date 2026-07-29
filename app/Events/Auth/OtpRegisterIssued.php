<?php

declare(strict_types=1);

namespace App\Events\Auth;

use App\Events\BaseDomainEvent;
use App\Services\Auth\OtpService;

/**
 * Auth events exist as documented seeds for the admin template editor
 * (Phase 22) but the Phase 11 listener intentionally NO-OPS for them —
 * Phase 3 already dispatches the SMS / email directly via
 * {@see OtpService} and the password-reset mailable.
 * Persisting a second copy would be duplicate / re-sending the OTP would
 * be a security regression.
 *
 * See docs/architecture/notifications.md § 3 (Open Q 4) and orchestrator
 * decision 2.
 */
final class OtpRegisterIssued extends BaseDomainEvent
{
    public function __construct(
        public readonly string $phone,
        public readonly ?int $userId = null,
    ) {
        parent::__construct($userId);
    }

    public function templateKey(): string
    {
        return 'auth.otp_register';
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'phone' => $this->phone,
            'user_id' => $this->userId,
        ];
    }
}
