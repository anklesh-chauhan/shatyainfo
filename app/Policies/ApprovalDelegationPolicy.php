<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\ApprovalDelegation;
use Illuminate\Auth\Access\HandlesAuthorization;

class ApprovalDelegationPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ApprovalDelegation');
    }

    public function view(AuthUser $authUser, ApprovalDelegation $approvalDelegation): bool
    {
        return $authUser->can('View:ApprovalDelegation');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ApprovalDelegation');
    }

    public function update(AuthUser $authUser, ApprovalDelegation $approvalDelegation): bool
    {
        return $authUser->can('Update:ApprovalDelegation');
    }

    public function delete(AuthUser $authUser, ApprovalDelegation $approvalDelegation): bool
    {
        return $authUser->can('Delete:ApprovalDelegation');
    }

    public function restore(AuthUser $authUser, ApprovalDelegation $approvalDelegation): bool
    {
        return $authUser->can('Restore:ApprovalDelegation');
    }

    public function forceDelete(AuthUser $authUser, ApprovalDelegation $approvalDelegation): bool
    {
        return $authUser->can('ForceDelete:ApprovalDelegation');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:ApprovalDelegation');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ApprovalDelegation');
    }

    public function replicate(AuthUser $authUser, ApprovalDelegation $approvalDelegation): bool
    {
        return $authUser->can('Replicate:ApprovalDelegation');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ApprovalDelegation');
    }

    public function viewOwnTerritory(AuthUser $authUser, ApprovalDelegation $approvalDelegation): bool
    {
        return $authUser->can('ViewOwnTerritory:ApprovalDelegation');
    }

    public function viewOwnOU(AuthUser $authUser, ApprovalDelegation $approvalDelegation): bool
    {
        return $authUser->can('ViewOwnOU:ApprovalDelegation');
    }

    public function viewOwn(AuthUser $authUser, ApprovalDelegation $approvalDelegation): bool
    {
        return $authUser->can('ViewOwn:ApprovalDelegation');
    }

    public function overrideApproval(AuthUser $authUser, ApprovalDelegation $approvalDelegation): bool
    {
        return $authUser->can('OverrideApproval:ApprovalDelegation');
    }

}