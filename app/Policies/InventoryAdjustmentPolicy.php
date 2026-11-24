<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\InventoryAdjustment;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class InventoryAdjustmentPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:InventoryAdjustment');
    }

    public function view(AuthUser $authUser, InventoryAdjustment $inventoryAdjustment): bool
    {
        return $authUser->can('View:InventoryAdjustment');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:InventoryAdjustment');
    }

    public function update(AuthUser $authUser, InventoryAdjustment $inventoryAdjustment): bool
    {
        return $authUser->can('Update:InventoryAdjustment');
    }

    public function delete(AuthUser $authUser, InventoryAdjustment $inventoryAdjustment): bool
    {
        return $authUser->can('Delete:InventoryAdjustment');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:InventoryAdjustment');
    }

    public function restore(AuthUser $authUser, InventoryAdjustment $inventoryAdjustment): bool
    {
        return $authUser->can('Restore:InventoryAdjustment');
    }

    public function forceDelete(AuthUser $authUser, InventoryAdjustment $inventoryAdjustment): bool
    {
        return $authUser->can('ForceDelete:InventoryAdjustment');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:InventoryAdjustment');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:InventoryAdjustment');
    }

    public function replicate(AuthUser $authUser, InventoryAdjustment $inventoryAdjustment): bool
    {
        return $authUser->can('Replicate:InventoryAdjustment');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:InventoryAdjustment');
    }

    public function viewOwnTerritory(AuthUser $authUser, InventoryAdjustment $inventoryAdjustment): bool
    {
        return $authUser->can('ViewOwnTerritory:InventoryAdjustment');
    }

    public function viewOwnOU(AuthUser $authUser, InventoryAdjustment $inventoryAdjustment): bool
    {
        return $authUser->can('ViewOwnOU:InventoryAdjustment');
    }

    public function viewOwn(AuthUser $authUser, InventoryAdjustment $inventoryAdjustment): bool
    {
        return $authUser->can('ViewOwn:InventoryAdjustment');
    }

    public function overrideApproval(AuthUser $authUser, InventoryAdjustment $inventoryAdjustment): bool
    {
        return $authUser->can('OverrideApproval:InventoryAdjustment');
    }
}
