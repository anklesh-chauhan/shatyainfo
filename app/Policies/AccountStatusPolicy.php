<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\AccountStatus;
use Illuminate\Auth\Access\HandlesAuthorization;

class AccountStatusPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:AccountStatus');
    }

    public function view(AuthUser $authUser, AccountStatus $accountStatus): bool
    {
        return $authUser->can('View:AccountStatus');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:AccountStatus');
    }

    public function update(AuthUser $authUser, AccountStatus $accountStatus): bool
    {
        return $authUser->can('Update:AccountStatus');
    }

    public function delete(AuthUser $authUser, AccountStatus $accountStatus): bool
    {
        return $authUser->can('Delete:AccountStatus');
    }

    public function restore(AuthUser $authUser, AccountStatus $accountStatus): bool
    {
        return $authUser->can('Restore:AccountStatus');
    }

    public function forceDelete(AuthUser $authUser, AccountStatus $accountStatus): bool
    {
        return $authUser->can('ForceDelete:AccountStatus');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:AccountStatus');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:AccountStatus');
    }

    public function replicate(AuthUser $authUser, AccountStatus $accountStatus): bool
    {
        return $authUser->can('Replicate:AccountStatus');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:AccountStatus');
    }

    public function viewOwnTerritory(AuthUser $authUser, AccountStatus $accountStatus): bool
    {
        return $authUser->can('ViewOwnTerritory:AccountStatus');
    }

    public function viewOwnOU(AuthUser $authUser, AccountStatus $accountStatus): bool
    {
        return $authUser->can('ViewOwnOU:AccountStatus');
    }

    public function viewOwn(AuthUser $authUser, AccountStatus $accountStatus): bool
    {
        return $authUser->can('ViewOwn:AccountStatus');
    }

    public function overrideApproval(AuthUser $authUser, AccountStatus $accountStatus): bool
    {
        return $authUser->can('OverrideApproval:AccountStatus');
    }

}