<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\LeaveCancellation;
use Illuminate\Auth\Access\HandlesAuthorization;

class LeaveCancellationPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:LeaveCancellation');
    }

    public function view(AuthUser $authUser, LeaveCancellation $leaveCancellation): bool
    {
        return $authUser->can('View:LeaveCancellation');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:LeaveCancellation');
    }

    public function update(AuthUser $authUser, LeaveCancellation $leaveCancellation): bool
    {
        return $authUser->can('Update:LeaveCancellation');
    }

    public function delete(AuthUser $authUser, LeaveCancellation $leaveCancellation): bool
    {
        return $authUser->can('Delete:LeaveCancellation');
    }

    public function restore(AuthUser $authUser, LeaveCancellation $leaveCancellation): bool
    {
        return $authUser->can('Restore:LeaveCancellation');
    }

    public function forceDelete(AuthUser $authUser, LeaveCancellation $leaveCancellation): bool
    {
        return $authUser->can('ForceDelete:LeaveCancellation');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:LeaveCancellation');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:LeaveCancellation');
    }

    public function replicate(AuthUser $authUser, LeaveCancellation $leaveCancellation): bool
    {
        return $authUser->can('Replicate:LeaveCancellation');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:LeaveCancellation');
    }

    public function viewOwnTerritory(AuthUser $authUser, LeaveCancellation $leaveCancellation): bool
    {
        return $authUser->can('ViewOwnTerritory:LeaveCancellation');
    }

    public function viewOwnOU(AuthUser $authUser, LeaveCancellation $leaveCancellation): bool
    {
        return $authUser->can('ViewOwnOU:LeaveCancellation');
    }

    public function viewOwn(AuthUser $authUser, LeaveCancellation $leaveCancellation): bool
    {
        return $authUser->can('ViewOwn:LeaveCancellation');
    }

    public function overrideApproval(AuthUser $authUser, LeaveCancellation $leaveCancellation): bool
    {
        return $authUser->can('OverrideApproval:LeaveCancellation');
    }

}