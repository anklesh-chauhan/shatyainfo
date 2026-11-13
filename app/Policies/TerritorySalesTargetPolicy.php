<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\TerritorySalesTarget;
use Illuminate\Auth\Access\HandlesAuthorization;

class TerritorySalesTargetPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:TerritorySalesTarget');
    }

    public function view(AuthUser $authUser, TerritorySalesTarget $territorySalesTarget): bool
    {
        return $authUser->can('View:TerritorySalesTarget');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:TerritorySalesTarget');
    }

    public function update(AuthUser $authUser, TerritorySalesTarget $territorySalesTarget): bool
    {
        return $authUser->can('Update:TerritorySalesTarget');
    }

    public function delete(AuthUser $authUser, TerritorySalesTarget $territorySalesTarget): bool
    {
        return $authUser->can('Delete:TerritorySalesTarget');
    }

    public function restore(AuthUser $authUser, TerritorySalesTarget $territorySalesTarget): bool
    {
        return $authUser->can('Restore:TerritorySalesTarget');
    }

    public function forceDelete(AuthUser $authUser, TerritorySalesTarget $territorySalesTarget): bool
    {
        return $authUser->can('ForceDelete:TerritorySalesTarget');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:TerritorySalesTarget');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:TerritorySalesTarget');
    }

    public function replicate(AuthUser $authUser, TerritorySalesTarget $territorySalesTarget): bool
    {
        return $authUser->can('Replicate:TerritorySalesTarget');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:TerritorySalesTarget');
    }

    public function viewOwnTerritory(AuthUser $authUser, TerritorySalesTarget $territorySalesTarget): bool
    {
        return $authUser->can('ViewOwnTerritory:TerritorySalesTarget');
    }

    public function viewOwnOU(AuthUser $authUser, TerritorySalesTarget $territorySalesTarget): bool
    {
        return $authUser->can('ViewOwnOU:TerritorySalesTarget');
    }

    public function viewOwn(AuthUser $authUser, TerritorySalesTarget $territorySalesTarget): bool
    {
        return $authUser->can('ViewOwn:TerritorySalesTarget');
    }

    public function overrideApproval(AuthUser $authUser, TerritorySalesTarget $territorySalesTarget): bool
    {
        return $authUser->can('OverrideApproval:TerritorySalesTarget');
    }

}