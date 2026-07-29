<?php

declare(strict_types=1);

namespace App\Events\Auth;

use App\Events\BaseDomainEvent;

/**
 * No-op per orchestrator decision 2 — see {@see OtpRegisterIssued}.
 */
final class PasswordResetEmail extends BaseDomainEvent
{
    public function __construct(
        public readonly int $userId,
        public readonly string $email,
    ) {
        parent::__construct($userId);
    }

    public function templateKey(): string
    {
        return 'auth.password_reset_email';
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'user_id' => $this->userId,
            'email' => $this->email,
        ];
    }
}
