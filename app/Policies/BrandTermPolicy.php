<?php

namespace App\Policies;

use App\Models\BrandTerm;
use App\Models\User;

/** Everyone sees the agreements; administrators change them (they drive every margin). */
class BrandTermPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, BrandTerm $term): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, BrandTerm $term): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, BrandTerm $term): bool
    {
        return $user->isAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }
}
