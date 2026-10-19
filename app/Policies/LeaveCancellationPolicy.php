<?php

namespace App\Policies;

use App\Models\LeaveCancellation;
use App\Models\User;

class LeaveCancellationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:LeaveCancellation');
    }

    public function view(User $user, LeaveCancellation $record): bool
    {
        return $user->can('View:LeaveCancellation');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, LeaveCancellation $record): bool
    {
        return false;
    }

    public function delete(User $user, LeaveCancellation $record): bool
    {
        return false;
    }
}
