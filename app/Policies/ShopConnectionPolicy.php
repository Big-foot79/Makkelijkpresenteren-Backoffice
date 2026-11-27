<?php

namespace App\Policies;

use App\Models\ShopConnection;
use App\Models\User;

/** The shop connection holds the API key: administrators only. */
class ShopConnectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, ShopConnection $connection): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ShopConnection $connection): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, ShopConnection $connection): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
