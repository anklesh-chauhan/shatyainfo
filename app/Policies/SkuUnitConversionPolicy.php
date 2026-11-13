<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\SkuUnitConversion;
use Illuminate\Auth\Access\HandlesAuthorization;

class SkuUnitConversionPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SkuUnitConversion');
    }

    public function view(AuthUser $authUser, SkuUnitConversion $skuUnitConversion): bool
    {
        return $authUser->can('View:SkuUnitConversion');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SkuUnitConversion');
    }

    public function update(AuthUser $authUser, SkuUnitConversion $skuUnitConversion): bool
    {
        return $authUser->can('Update:SkuUnitConversion');
    }

    public function delete(AuthUser $authUser, SkuUnitConversion $skuUnitConversion): bool
    {
        return $authUser->can('Delete:SkuUnitConversion');
    }

    public function restore(AuthUser $authUser, SkuUnitConversion $skuUnitConversion): bool
    {
        return $authUser->can('Restore:SkuUnitConversion');
    }

    public function forceDelete(AuthUser $authUser, SkuUnitConversion $skuUnitConversion): bool
    {
        return $authUser->can('ForceDelete:SkuUnitConversion');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SkuUnitConversion');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SkuUnitConversion');
    }

    public function replicate(AuthUser $authUser, SkuUnitConversion $skuUnitConversion): bool
    {
        return $authUser->can('Replicate:SkuUnitConversion');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SkuUnitConversion');
    }

    public function viewOwnTerritory(AuthUser $authUser, SkuUnitConversion $skuUnitConversion): bool
    {
        return $authUser->can('ViewOwnTerritory:SkuUnitConversion');
    }

    public function viewOwnOU(AuthUser $authUser, SkuUnitConversion $skuUnitConversion): bool
    {
        return $authUser->can('ViewOwnOU:SkuUnitConversion');
    }

    public function viewOwn(AuthUser $authUser, SkuUnitConversion $skuUnitConversion): bool
    {
        return $authUser->can('ViewOwn:SkuUnitConversion');
    }

    public function overrideApproval(AuthUser $authUser, SkuUnitConversion $skuUnitConversion): bool
    {
        return $authUser->can('OverrideApproval:SkuUnitConversion');
    }

}