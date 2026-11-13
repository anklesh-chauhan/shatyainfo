<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\PromotionalScheme;
use Illuminate\Auth\Access\HandlesAuthorization;

class PromotionalSchemePolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:PromotionalScheme');
    }

    public function view(AuthUser $authUser, PromotionalScheme $promotionalScheme): bool
    {
        return $authUser->can('View:PromotionalScheme');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:PromotionalScheme');
    }

    public function update(AuthUser $authUser, PromotionalScheme $promotionalScheme): bool
    {
        return $authUser->can('Update:PromotionalScheme');
    }

    public function delete(AuthUser $authUser, PromotionalScheme $promotionalScheme): bool
    {
        return $authUser->can('Delete:PromotionalScheme');
    }

    public function restore(AuthUser $authUser, PromotionalScheme $promotionalScheme): bool
    {
        return $authUser->can('Restore:PromotionalScheme');
    }

    public function forceDelete(AuthUser $authUser, PromotionalScheme $promotionalScheme): bool
    {
        return $authUser->can('ForceDelete:PromotionalScheme');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:PromotionalScheme');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:PromotionalScheme');
    }

    public function replicate(AuthUser $authUser, PromotionalScheme $promotionalScheme): bool
    {
        return $authUser->can('Replicate:PromotionalScheme');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:PromotionalScheme');
    }

    public function viewOwnTerritory(AuthUser $authUser, PromotionalScheme $promotionalScheme): bool
    {
        return $authUser->can('ViewOwnTerritory:PromotionalScheme');
    }

    public function viewOwnOU(AuthUser $authUser, PromotionalScheme $promotionalScheme): bool
    {
        return $authUser->can('ViewOwnOU:PromotionalScheme');
    }

    public function viewOwn(AuthUser $authUser, PromotionalScheme $promotionalScheme): bool
    {
        return $authUser->can('ViewOwn:PromotionalScheme');
    }

    public function overrideApproval(AuthUser $authUser, PromotionalScheme $promotionalScheme): bool
    {
        return $authUser->can('OverrideApproval:PromotionalScheme');
    }

}