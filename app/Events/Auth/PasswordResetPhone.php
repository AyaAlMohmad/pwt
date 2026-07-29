<?php

declare(strict_types=1);

namespace App\Events\Auth;

use App\Events\BaseDomainEvent;

/**
 * No-op per orchestrator decision 2 — see {@see OtpRegisterIssued}.
 */
final class PasswordResetPhone extends BaseDomainEvent
{
    public function __construct(
        public readonly int $userId,
        public readonly string $phone,
    ) {
        parent::__construct($userId);
    }

    public function templateKey(): string
    {
        return 'auth.password_reset_phone';
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'user_id' => $this->userId,
            'phone' => $this->phone,
        ];
    }
}
