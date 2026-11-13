<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\SecondaryOcrSource;
use Illuminate\Auth\Access\HandlesAuthorization;

class SecondaryOcrSourcePolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SecondaryOcrSource');
    }

    public function view(AuthUser $authUser, SecondaryOcrSource $secondaryOcrSource): bool
    {
        return $authUser->can('View:SecondaryOcrSource');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SecondaryOcrSource');
    }

    public function update(AuthUser $authUser, SecondaryOcrSource $secondaryOcrSource): bool
    {
        return $authUser->can('Update:SecondaryOcrSource');
    }

    public function delete(AuthUser $authUser, SecondaryOcrSource $secondaryOcrSource): bool
    {
        return $authUser->can('Delete:SecondaryOcrSource');
    }

    public function restore(AuthUser $authUser, SecondaryOcrSource $secondaryOcrSource): bool
    {
        return $authUser->can('Restore:SecondaryOcrSource');
    }

    public function forceDelete(AuthUser $authUser, SecondaryOcrSource $secondaryOcrSource): bool
    {
        return $authUser->can('ForceDelete:SecondaryOcrSource');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SecondaryOcrSource');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SecondaryOcrSource');
    }

    public function replicate(AuthUser $authUser, SecondaryOcrSource $secondaryOcrSource): bool
    {
        return $authUser->can('Replicate:SecondaryOcrSource');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SecondaryOcrSource');
    }

    public function viewOwnTerritory(AuthUser $authUser, SecondaryOcrSource $secondaryOcrSource): bool
    {
        return $authUser->can('ViewOwnTerritory:SecondaryOcrSource');
    }

    public function viewOwnOU(AuthUser $authUser, SecondaryOcrSource $secondaryOcrSource): bool
    {
        return $authUser->can('ViewOwnOU:SecondaryOcrSource');
    }

    public function viewOwn(AuthUser $authUser, SecondaryOcrSource $secondaryOcrSource): bool
    {
        return $authUser->can('ViewOwn:SecondaryOcrSource');
    }

    public function overrideApproval(AuthUser $authUser, SecondaryOcrSource $secondaryOcrSource): bool
    {
        return $authUser->can('OverrideApproval:SecondaryOcrSource');
    }

}