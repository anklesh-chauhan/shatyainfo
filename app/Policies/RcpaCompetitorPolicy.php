<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\RcpaCompetitor;
use Illuminate\Auth\Access\HandlesAuthorization;

class RcpaCompetitorPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:RcpaCompetitor');
    }

    public function view(AuthUser $authUser, RcpaCompetitor $rcpaCompetitor): bool
    {
        return $authUser->can('View:RcpaCompetitor');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:RcpaCompetitor');
    }

    public function update(AuthUser $authUser, RcpaCompetitor $rcpaCompetitor): bool
    {
        return $authUser->can('Update:RcpaCompetitor');
    }

    public function delete(AuthUser $authUser, RcpaCompetitor $rcpaCompetitor): bool
    {
        return $authUser->can('Delete:RcpaCompetitor');
    }

    public function restore(AuthUser $authUser, RcpaCompetitor $rcpaCompetitor): bool
    {
        return $authUser->can('Restore:RcpaCompetitor');
    }

    public function forceDelete(AuthUser $authUser, RcpaCompetitor $rcpaCompetitor): bool
    {
        return $authUser->can('ForceDelete:RcpaCompetitor');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:RcpaCompetitor');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:RcpaCompetitor');
    }

    public function replicate(AuthUser $authUser, RcpaCompetitor $rcpaCompetitor): bool
    {
        return $authUser->can('Replicate:RcpaCompetitor');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:RcpaCompetitor');
    }

    public function viewOwnTerritory(AuthUser $authUser, RcpaCompetitor $rcpaCompetitor): bool
    {
        return $authUser->can('ViewOwnTerritory:RcpaCompetitor');
    }

    public function viewOwnOU(AuthUser $authUser, RcpaCompetitor $rcpaCompetitor): bool
    {
        return $authUser->can('ViewOwnOU:RcpaCompetitor');
    }

    public function viewOwn(AuthUser $authUser, RcpaCompetitor $rcpaCompetitor): bool
    {
        return $authUser->can('ViewOwn:RcpaCompetitor');
    }

    public function overrideApproval(AuthUser $authUser, RcpaCompetitor $rcpaCompetitor): bool
    {
        return $authUser->can('OverrideApproval:RcpaCompetitor');
    }

}