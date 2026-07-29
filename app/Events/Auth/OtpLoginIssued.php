<?php

declare(strict_types=1);

namespace App\Events\Auth;

use App\Events\BaseDomainEvent;

/**
 * No-op per orchestrator decision 2 — see {@see OtpRegisterIssued}.
 */
final class OtpLoginIssued extends BaseDomainEvent
{
    public function __construct(
        public readonly string $phone,
        public readonly ?int $userId = null,
    ) {
        parent::__construct($userId);
    }

    public function templateKey(): string
    {
        return 'auth.otp_login';
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
