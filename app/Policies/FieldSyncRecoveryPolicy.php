<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\FieldSyncRecovery;
use Illuminate\Auth\Access\HandlesAuthorization;

class FieldSyncRecoveryPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:FieldSyncRecovery');
    }

    public function view(AuthUser $authUser, FieldSyncRecovery $fieldSyncRecovery): bool
    {
        return $authUser->can('View:FieldSyncRecovery');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:FieldSyncRecovery');
    }

    public function update(AuthUser $authUser, FieldSyncRecovery $fieldSyncRecovery): bool
    {
        return $authUser->can('Update:FieldSyncRecovery');
    }

    public function delete(AuthUser $authUser, FieldSyncRecovery $fieldSyncRecovery): bool
    {
        return $authUser->can('Delete:FieldSyncRecovery');
    }

    public function restore(AuthUser $authUser, FieldSyncRecovery $fieldSyncRecovery): bool
    {
        return $authUser->can('Restore:FieldSyncRecovery');
    }

    public function forceDelete(AuthUser $authUser, FieldSyncRecovery $fieldSyncRecovery): bool
    {
        return $authUser->can('ForceDelete:FieldSyncRecovery');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:FieldSyncRecovery');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:FieldSyncRecovery');
    }

    public function replicate(AuthUser $authUser, FieldSyncRecovery $fieldSyncRecovery): bool
    {
        return $authUser->can('Replicate:FieldSyncRecovery');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:FieldSyncRecovery');
    }

    public function viewOwnTerritory(AuthUser $authUser, FieldSyncRecovery $fieldSyncRecovery): bool
    {
        return $authUser->can('ViewOwnTerritory:FieldSyncRecovery');
    }

    public function viewOwnOU(AuthUser $authUser, FieldSyncRecovery $fieldSyncRecovery): bool
    {
        return $authUser->can('ViewOwnOU:FieldSyncRecovery');
    }

    public function viewOwn(AuthUser $authUser, FieldSyncRecovery $fieldSyncRecovery): bool
    {
        return $authUser->can('ViewOwn:FieldSyncRecovery');
    }

    public function overrideApproval(AuthUser $authUser, FieldSyncRecovery $fieldSyncRecovery): bool
    {
        return $authUser->can('OverrideApproval:FieldSyncRecovery');
    }

}