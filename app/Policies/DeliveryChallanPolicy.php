<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DeliveryChallan;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class DeliveryChallanPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:DeliveryChallan');
    }

    public function view(AuthUser $authUser, DeliveryChallan $deliveryChallan): bool
    {
        return $authUser->can('View:DeliveryChallan');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:DeliveryChallan');
    }

    public function update(AuthUser $authUser, DeliveryChallan $deliveryChallan): bool
    {
        return $authUser->can('Update:DeliveryChallan');
    }

    public function delete(AuthUser $authUser, DeliveryChallan $deliveryChallan): bool
    {
        return $authUser->can('Delete:DeliveryChallan');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:DeliveryChallan');
    }

    public function restore(AuthUser $authUser, DeliveryChallan $deliveryChallan): bool
    {
        return $authUser->can('Restore:DeliveryChallan');
    }

    public function forceDelete(AuthUser $authUser, DeliveryChallan $deliveryChallan): bool
    {
        return $authUser->can('ForceDelete:DeliveryChallan');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:DeliveryChallan');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:DeliveryChallan');
    }

    public function replicate(AuthUser $authUser, DeliveryChallan $deliveryChallan): bool
    {
        return $authUser->can('Replicate:DeliveryChallan');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:DeliveryChallan');
    }

    public function viewOwnTerritory(AuthUser $authUser, DeliveryChallan $deliveryChallan): bool
    {
        return $authUser->can('ViewOwnTerritory:DeliveryChallan');
    }

    public function viewOwnOU(AuthUser $authUser, DeliveryChallan $deliveryChallan): bool
    {
        return $authUser->can('ViewOwnOU:DeliveryChallan');
    }

    public function viewOwn(AuthUser $authUser, DeliveryChallan $deliveryChallan): bool
    {
        return $authUser->can('ViewOwn:DeliveryChallan');
    }

    public function overrideApproval(AuthUser $authUser, DeliveryChallan $deliveryChallan): bool
    {
        return $authUser->can('OverrideApproval:DeliveryChallan');
    }
}
