<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\RcpaObservation;
use Illuminate\Auth\Access\HandlesAuthorization;

class RcpaObservationPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:RcpaObservation');
    }

    public function view(AuthUser $authUser, RcpaObservation $rcpaObservation): bool
    {
        return $authUser->can('View:RcpaObservation');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:RcpaObservation');
    }

    public function update(AuthUser $authUser, RcpaObservation $rcpaObservation): bool
    {
        return $authUser->can('Update:RcpaObservation');
    }

    public function delete(AuthUser $authUser, RcpaObservation $rcpaObservation): bool
    {
        return $authUser->can('Delete:RcpaObservation');
    }

    public function restore(AuthUser $authUser, RcpaObservation $rcpaObservation): bool
    {
        return $authUser->can('Restore:RcpaObservation');
    }

    public function forceDelete(AuthUser $authUser, RcpaObservation $rcpaObservation): bool
    {
        return $authUser->can('ForceDelete:RcpaObservation');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:RcpaObservation');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:RcpaObservation');
    }

    public function replicate(AuthUser $authUser, RcpaObservation $rcpaObservation): bool
    {
        return $authUser->can('Replicate:RcpaObservation');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:RcpaObservation');
    }

    public function viewOwnTerritory(AuthUser $authUser, RcpaObservation $rcpaObservation): bool
    {
        return $authUser->can('ViewOwnTerritory:RcpaObservation');
    }

    public function viewOwnOU(AuthUser $authUser, RcpaObservation $rcpaObservation): bool
    {
        return $authUser->can('ViewOwnOU:RcpaObservation');
    }

    public function viewOwn(AuthUser $authUser, RcpaObservation $rcpaObservation): bool
    {
        return $authUser->can('ViewOwn:RcpaObservation');
    }

    public function overrideApproval(AuthUser $authUser, RcpaObservation $rcpaObservation): bool
    {
        return $authUser->can('OverrideApproval:RcpaObservation');
    }

}