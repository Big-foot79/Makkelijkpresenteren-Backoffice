<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

/**
 * Everyone in the panel sees and handles tasks. Tasks are created by the system and by agents,
 * never by hand, and never deleted (they are part of the history).
 */
class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Task $task): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Task $task): bool
    {
        return true;
    }

    public function delete(User $user, Task $task): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
