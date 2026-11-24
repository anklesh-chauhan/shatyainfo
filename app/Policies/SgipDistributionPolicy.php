<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SgipDistribution;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SgipDistributionPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SgipDistribution');
    }

    public function view(AuthUser $authUser, SgipDistribution $sgipDistribution): bool
    {
        return $authUser->can('View:SgipDistribution');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SgipDistribution');
    }

    public function update(AuthUser $authUser, SgipDistribution $sgipDistribution): bool
    {
        return $authUser->can('Update:SgipDistribution');
    }

    public function delete(AuthUser $authUser, SgipDistribution $sgipDistribution): bool
    {
        return $authUser->can('Delete:SgipDistribution') && $sgipDistribution->isEditable();
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:SgipDistribution');
    }

    public function restore(AuthUser $authUser, SgipDistribution $sgipDistribution): bool
    {
        return $authUser->can('Restore:SgipDistribution') && $sgipDistribution->trashed()
            && $sgipDistribution->approval_status === 'draft' && ! $sgipDistribution->isInventoryPosted() && $sgipDistribution->inventory_reserved_at === null;
    }

    public function forceDelete(AuthUser $authUser, SgipDistribution $sgipDistribution): bool
    {
        return $authUser->can('ForceDelete:SgipDistribution') && $sgipDistribution->trashed()
            && $sgipDistribution->approval_status === 'draft' && ! $sgipDistribution->isInventoryPosted() && $sgipDistribution->inventory_reserved_at === null;
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SgipDistribution');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SgipDistribution');
    }

    public function replicate(AuthUser $authUser, SgipDistribution $sgipDistribution): bool
    {
        return $authUser->can('Replicate:SgipDistribution');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SgipDistribution');
    }

    public function viewOwnTerritory(AuthUser $authUser, SgipDistribution $sgipDistribution): bool
    {
        return $authUser->can('ViewOwnTerritory:SgipDistribution');
    }

    public function viewOwnOU(AuthUser $authUser, SgipDistribution $sgipDistribution): bool
    {
        return $authUser->can('ViewOwnOU:SgipDistribution');
    }

    public function viewOwn(AuthUser $authUser, SgipDistribution $sgipDistribution): bool
    {
        return $authUser->can('ViewOwn:SgipDistribution');
    }

    public function overrideApproval(AuthUser $authUser, SgipDistribution $sgipDistribution): bool
    {
        return $authUser->can('OverrideApproval:SgipDistribution');
    }
}
