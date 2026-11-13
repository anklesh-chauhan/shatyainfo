<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\FieldDevice;
use Illuminate\Auth\Access\HandlesAuthorization;

class FieldDevicePolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:FieldDevice');
    }

    public function view(AuthUser $authUser, FieldDevice $fieldDevice): bool
    {
        return $authUser->can('View:FieldDevice');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:FieldDevice');
    }

    public function update(AuthUser $authUser, FieldDevice $fieldDevice): bool
    {
        return $authUser->can('Update:FieldDevice');
    }

    public function delete(AuthUser $authUser, FieldDevice $fieldDevice): bool
    {
        return $authUser->can('Delete:FieldDevice');
    }

    public function restore(AuthUser $authUser, FieldDevice $fieldDevice): bool
    {
        return $authUser->can('Restore:FieldDevice');
    }

    public function forceDelete(AuthUser $authUser, FieldDevice $fieldDevice): bool
    {
        return $authUser->can('ForceDelete:FieldDevice');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:FieldDevice');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:FieldDevice');
    }

    public function replicate(AuthUser $authUser, FieldDevice $fieldDevice): bool
    {
        return $authUser->can('Replicate:FieldDevice');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:FieldDevice');
    }

    public function viewOwnTerritory(AuthUser $authUser, FieldDevice $fieldDevice): bool
    {
        return $authUser->can('ViewOwnTerritory:FieldDevice');
    }

    public function viewOwnOU(AuthUser $authUser, FieldDevice $fieldDevice): bool
    {
        return $authUser->can('ViewOwnOU:FieldDevice');
    }

    public function viewOwn(AuthUser $authUser, FieldDevice $fieldDevice): bool
    {
        return $authUser->can('ViewOwn:FieldDevice');
    }

    public function overrideApproval(AuthUser $authUser, FieldDevice $fieldDevice): bool
    {
        return $authUser->can('OverrideApproval:FieldDevice');
    }

}