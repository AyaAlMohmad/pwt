<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PaymentCard;
use App\Models\User;

final class PaymentCardPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PaymentCard $card): bool
    {
        return $user->id === $card->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, PaymentCard $card): bool
    {
        return $user->id === $card->user_id;
    }

    public function delete(User $user, PaymentCard $card): bool
    {
        return $user->id === $card->user_id;
    }
}
