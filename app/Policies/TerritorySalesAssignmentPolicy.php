<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\TerritorySalesAssignment;
use Illuminate\Auth\Access\HandlesAuthorization;

class TerritorySalesAssignmentPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:TerritorySalesAssignment');
    }

    public function view(AuthUser $authUser, TerritorySalesAssignment $territorySalesAssignment): bool
    {
        return $authUser->can('View:TerritorySalesAssignment');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:TerritorySalesAssignment');
    }

    public function update(AuthUser $authUser, TerritorySalesAssignment $territorySalesAssignment): bool
    {
        return $authUser->can('Update:TerritorySalesAssignment');
    }

    public function delete(AuthUser $authUser, TerritorySalesAssignment $territorySalesAssignment): bool
    {
        return $authUser->can('Delete:TerritorySalesAssignment');
    }

    public function restore(AuthUser $authUser, TerritorySalesAssignment $territorySalesAssignment): bool
    {
        return $authUser->can('Restore:TerritorySalesAssignment');
    }

    public function forceDelete(AuthUser $authUser, TerritorySalesAssignment $territorySalesAssignment): bool
    {
        return $authUser->can('ForceDelete:TerritorySalesAssignment');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:TerritorySalesAssignment');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:TerritorySalesAssignment');
    }

    public function replicate(AuthUser $authUser, TerritorySalesAssignment $territorySalesAssignment): bool
    {
        return $authUser->can('Replicate:TerritorySalesAssignment');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:TerritorySalesAssignment');
    }

    public function viewOwnTerritory(AuthUser $authUser, TerritorySalesAssignment $territorySalesAssignment): bool
    {
        return $authUser->can('ViewOwnTerritory:TerritorySalesAssignment');
    }

    public function viewOwnOU(AuthUser $authUser, TerritorySalesAssignment $territorySalesAssignment): bool
    {
        return $authUser->can('ViewOwnOU:TerritorySalesAssignment');
    }

    public function viewOwn(AuthUser $authUser, TerritorySalesAssignment $territorySalesAssignment): bool
    {
        return $authUser->can('ViewOwn:TerritorySalesAssignment');
    }

    public function overrideApproval(AuthUser $authUser, TerritorySalesAssignment $territorySalesAssignment): bool
    {
        return $authUser->can('OverrideApproval:TerritorySalesAssignment');
    }

}