<?php

namespace App\Policies;

use App\Models\User;

/** Records written only by the system (runs, change history): everyone may look, nobody may edit. */
abstract class ReadOnlyPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, $model): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, $model): bool
    {
        return false;
    }

    public function delete(User $user, $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
