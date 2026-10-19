<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\InventoryTransfer;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class InventoryTransferPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:InventoryTransfer');
    }

    public function view(AuthUser $authUser, InventoryTransfer $inventoryTransfer): bool
    {
        return $authUser->can('View:InventoryTransfer');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:InventoryTransfer');
    }

    public function update(AuthUser $authUser, InventoryTransfer $inventoryTransfer): bool
    {
        return $authUser->can('Update:InventoryTransfer');
    }

    public function delete(AuthUser $authUser, InventoryTransfer $inventoryTransfer): bool
    {
        return $authUser->can('Delete:InventoryTransfer');
    }

    public function restore(AuthUser $authUser, InventoryTransfer $inventoryTransfer): bool
    {
        return $authUser->can('Restore:InventoryTransfer');
    }

    public function forceDelete(AuthUser $authUser, InventoryTransfer $inventoryTransfer): bool
    {
        return $authUser->can('ForceDelete:InventoryTransfer');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:InventoryTransfer');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:InventoryTransfer');
    }

    public function replicate(AuthUser $authUser, InventoryTransfer $inventoryTransfer): bool
    {
        return $authUser->can('Replicate:InventoryTransfer');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:InventoryTransfer');
    }

    public function viewOwnTerritory(AuthUser $authUser, InventoryTransfer $inventoryTransfer): bool
    {
        return $authUser->can('ViewOwnTerritory:InventoryTransfer');
    }

    public function viewOwnOU(AuthUser $authUser, InventoryTransfer $inventoryTransfer): bool
    {
        return $authUser->can('ViewOwnOU:InventoryTransfer');
    }

    public function viewOwn(AuthUser $authUser, InventoryTransfer $inventoryTransfer): bool
    {
        return $authUser->can('ViewOwn:InventoryTransfer');
    }

    public function overrideApproval(AuthUser $authUser, InventoryTransfer $inventoryTransfer): bool
    {
        return $authUser->can('OverrideApproval:InventoryTransfer');
    }
}
