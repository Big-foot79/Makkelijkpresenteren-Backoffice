<?php

namespace App\Policies;

use App\Models\FavoriteBrand;
use App\Models\User;

/** Everyone in the panel may keep the list of favourite brands. */
class FavoriteBrandPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, FavoriteBrand $brand): bool
    {
        return true;
    }

    public function delete(User $user, FavoriteBrand $brand): bool
    {
        return true;
    }

    public function reorder(User $user): bool
    {
        return true;
    }
}
