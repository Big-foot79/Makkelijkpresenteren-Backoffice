<?php

namespace App\Policies;

use App\Models\BrandAlias;
use App\Models\User;

/** Everyone sees the links; administrators change them. */
class BrandAliasPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, BrandAlias $alias): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, BrandAlias $alias): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, BrandAlias $alias): bool
    {
        return $user->isAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }
}
