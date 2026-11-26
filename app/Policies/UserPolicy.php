<?php

namespace App\Policies;

use App\Models\User;

/** Only administrators manage users and AI agents. */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, User $model): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, User $model): bool
    {
        return $user->isAdmin();
    }

    /** Nobody deletes their own account, so there is always an administrator left. */
    public function delete(User $user, User $model): bool
    {
        return $user->isAdmin() && ! $user->is($model);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
