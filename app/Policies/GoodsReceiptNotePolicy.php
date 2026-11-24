<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\GoodsReceiptNote;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class GoodsReceiptNotePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:GoodsReceiptNote');
    }

    public function view(AuthUser $authUser, GoodsReceiptNote $goodsReceiptNote): bool
    {
        return $authUser->can('View:GoodsReceiptNote');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:GoodsReceiptNote');
    }

    public function update(AuthUser $authUser, GoodsReceiptNote $goodsReceiptNote): bool
    {
        return $authUser->can('Update:GoodsReceiptNote');
    }

    public function delete(AuthUser $authUser, GoodsReceiptNote $goodsReceiptNote): bool
    {
        return $authUser->can('Delete:GoodsReceiptNote');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:GoodsReceiptNote');
    }

    public function restore(AuthUser $authUser, GoodsReceiptNote $goodsReceiptNote): bool
    {
        return $authUser->can('Restore:GoodsReceiptNote');
    }

    public function forceDelete(AuthUser $authUser, GoodsReceiptNote $goodsReceiptNote): bool
    {
        return $authUser->can('ForceDelete:GoodsReceiptNote');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:GoodsReceiptNote');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:GoodsReceiptNote');
    }

    public function replicate(AuthUser $authUser, GoodsReceiptNote $goodsReceiptNote): bool
    {
        return $authUser->can('Replicate:GoodsReceiptNote');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:GoodsReceiptNote');
    }

    public function viewOwnTerritory(AuthUser $authUser, GoodsReceiptNote $goodsReceiptNote): bool
    {
        return $authUser->can('ViewOwnTerritory:GoodsReceiptNote');
    }

    public function viewOwnOU(AuthUser $authUser, GoodsReceiptNote $goodsReceiptNote): bool
    {
        return $authUser->can('ViewOwnOU:GoodsReceiptNote');
    }

    public function viewOwn(AuthUser $authUser, GoodsReceiptNote $goodsReceiptNote): bool
    {
        return $authUser->can('ViewOwn:GoodsReceiptNote');
    }

    public function overrideApproval(AuthUser $authUser, GoodsReceiptNote $goodsReceiptNote): bool
    {
        return $authUser->can('OverrideApproval:GoodsReceiptNote');
    }
}
