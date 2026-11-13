<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\EDetailingMaterial;
use Illuminate\Auth\Access\HandlesAuthorization;

class EDetailingMaterialPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:EDetailingMaterial');
    }

    public function view(AuthUser $authUser, EDetailingMaterial $eDetailingMaterial): bool
    {
        return $authUser->can('View:EDetailingMaterial');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:EDetailingMaterial');
    }

    public function update(AuthUser $authUser, EDetailingMaterial $eDetailingMaterial): bool
    {
        return $authUser->can('Update:EDetailingMaterial');
    }

    public function delete(AuthUser $authUser, EDetailingMaterial $eDetailingMaterial): bool
    {
        return $authUser->can('Delete:EDetailingMaterial');
    }

    public function restore(AuthUser $authUser, EDetailingMaterial $eDetailingMaterial): bool
    {
        return $authUser->can('Restore:EDetailingMaterial');
    }

    public function forceDelete(AuthUser $authUser, EDetailingMaterial $eDetailingMaterial): bool
    {
        return $authUser->can('ForceDelete:EDetailingMaterial');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:EDetailingMaterial');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:EDetailingMaterial');
    }

    public function replicate(AuthUser $authUser, EDetailingMaterial $eDetailingMaterial): bool
    {
        return $authUser->can('Replicate:EDetailingMaterial');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:EDetailingMaterial');
    }

    public function viewOwnTerritory(AuthUser $authUser, EDetailingMaterial $eDetailingMaterial): bool
    {
        return $authUser->can('ViewOwnTerritory:EDetailingMaterial');
    }

    public function viewOwnOU(AuthUser $authUser, EDetailingMaterial $eDetailingMaterial): bool
    {
        return $authUser->can('ViewOwnOU:EDetailingMaterial');
    }

    public function viewOwn(AuthUser $authUser, EDetailingMaterial $eDetailingMaterial): bool
    {
        return $authUser->can('ViewOwn:EDetailingMaterial');
    }

    public function overrideApproval(AuthUser $authUser, EDetailingMaterial $eDetailingMaterial): bool
    {
        return $authUser->can('OverrideApproval:EDetailingMaterial');
    }

}