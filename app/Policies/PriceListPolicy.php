<?php

namespace App\Policies;

use App\Models\PriceList;
use App\Models\User;

/** Everyone may upload and read in a price list. */
class PriceListPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PriceList $list): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, PriceList $list): bool
    {
        return true;
    }

    public function delete(User $user, PriceList $list): bool
    {
        return $user->isAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }
}
