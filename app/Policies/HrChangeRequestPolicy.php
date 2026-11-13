<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\HrChangeRequest;
use Illuminate\Auth\Access\HandlesAuthorization;

class HrChangeRequestPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:HrChangeRequest');
    }

    public function view(AuthUser $authUser, HrChangeRequest $hrChangeRequest): bool
    {
        return $authUser->can('View:HrChangeRequest');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:HrChangeRequest');
    }

    public function update(AuthUser $authUser, HrChangeRequest $hrChangeRequest): bool
    {
        return $authUser->can('Update:HrChangeRequest');
    }

    public function delete(AuthUser $authUser, HrChangeRequest $hrChangeRequest): bool
    {
        return $authUser->can('Delete:HrChangeRequest');
    }

    public function restore(AuthUser $authUser, HrChangeRequest $hrChangeRequest): bool
    {
        return $authUser->can('Restore:HrChangeRequest');
    }

    public function forceDelete(AuthUser $authUser, HrChangeRequest $hrChangeRequest): bool
    {
        return $authUser->can('ForceDelete:HrChangeRequest');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:HrChangeRequest');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:HrChangeRequest');
    }

    public function replicate(AuthUser $authUser, HrChangeRequest $hrChangeRequest): bool
    {
        return $authUser->can('Replicate:HrChangeRequest');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:HrChangeRequest');
    }

    public function viewOwnTerritory(AuthUser $authUser, HrChangeRequest $hrChangeRequest): bool
    {
        return $authUser->can('ViewOwnTerritory:HrChangeRequest');
    }

    public function viewOwnOU(AuthUser $authUser, HrChangeRequest $hrChangeRequest): bool
    {
        return $authUser->can('ViewOwnOU:HrChangeRequest');
    }

    public function viewOwn(AuthUser $authUser, HrChangeRequest $hrChangeRequest): bool
    {
        return $authUser->can('ViewOwn:HrChangeRequest');
    }

    public function overrideApproval(AuthUser $authUser, HrChangeRequest $hrChangeRequest): bool
    {
        return $authUser->can('OverrideApproval:HrChangeRequest');
    }

}