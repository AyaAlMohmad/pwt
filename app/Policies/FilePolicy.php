<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\File;
use App\Models\User;

/**
 * Authorisation gate for {@see File} access.
 *
 * Per CLAUDE.md § 7.3 the ownership check is binary: matching `user_id`
 * succeeds, otherwise 403 (not 404). Admin roles are granted broader
 * read access for support / ops contexts but customers only ever see
 * (or delete) files they own.
 */
final class FilePolicy
{
    public function view(User $actor, File $file): bool
    {
        if ($file->user_id === $actor->id) {
            return true;
        }

        if ($file->is_public) {
            return true;
        }

        return $this->isPrivilegedAdmin($actor);
    }

    public function delete(User $actor, File $file): bool
    {
        if ($file->user_id === $actor->id) {
            return true;
        }

        return $actor->hasRole(UserRole::SuperAdmin->value);
    }

    private function isPrivilegedAdmin(User $actor): bool
    {
        return $actor->hasAnyRole([
            UserRole::SuperAdmin->value,
            UserRole::Operations->value,
            UserRole::Support->value,
        ]);
    }
}
