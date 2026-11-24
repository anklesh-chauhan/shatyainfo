<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SampleRequest;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SampleRequestPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SampleRequest');
    }

    public function view(AuthUser $authUser, SampleRequest $sampleRequest): bool
    {
        return $authUser->can('View:SampleRequest');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SampleRequest');
    }

    public function update(AuthUser $authUser, SampleRequest $sampleRequest): bool
    {
        return $authUser->can('Update:SampleRequest');
    }

    public function delete(AuthUser $authUser, SampleRequest $sampleRequest): bool
    {
        return $authUser->can('Delete:SampleRequest');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:SampleRequest');
    }

    public function restore(AuthUser $authUser, SampleRequest $sampleRequest): bool
    {
        return $authUser->can('Restore:SampleRequest');
    }

    public function forceDelete(AuthUser $authUser, SampleRequest $sampleRequest): bool
    {
        return $authUser->can('ForceDelete:SampleRequest');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SampleRequest');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SampleRequest');
    }

    public function replicate(AuthUser $authUser, SampleRequest $sampleRequest): bool
    {
        return $authUser->can('Replicate:SampleRequest');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SampleRequest');
    }

    public function viewOwnTerritory(AuthUser $authUser, SampleRequest $sampleRequest): bool
    {
        return $authUser->can('ViewOwnTerritory:SampleRequest');
    }

    public function viewOwnOU(AuthUser $authUser, SampleRequest $sampleRequest): bool
    {
        return $authUser->can('ViewOwnOU:SampleRequest');
    }

    public function viewOwn(AuthUser $authUser, SampleRequest $sampleRequest): bool
    {
        return $authUser->can('ViewOwn:SampleRequest');
    }

    public function overrideApproval(AuthUser $authUser, SampleRequest $sampleRequest): bool
    {
        return $authUser->can('OverrideApproval:SampleRequest');
    }
}
