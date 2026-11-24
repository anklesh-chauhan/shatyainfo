<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\SampleIssueStatus;
use App\Models\SampleIssue;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SampleIssuePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SampleIssue');
    }

    public function view(AuthUser $authUser, SampleIssue $sampleIssue): bool
    {
        return $authUser->can('View:SampleIssue');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SampleIssue');
    }

    public function update(AuthUser $authUser, SampleIssue $sampleIssue): bool
    {
        return $authUser->can('Update:SampleIssue');
    }

    public function delete(AuthUser $authUser, SampleIssue $sampleIssue): bool
    {
        return $authUser->can('Delete:SampleIssue') && $sampleIssue->isEditable();
    }

    public function restore(AuthUser $authUser, SampleIssue $sampleIssue): bool
    {
        return $authUser->can('Restore:SampleIssue') && $sampleIssue->trashed() && ! $sampleIssue->isPosted()
            && $sampleIssue->status === SampleIssueStatus::Draft;
    }

    public function forceDelete(AuthUser $authUser, SampleIssue $sampleIssue): bool
    {
        return $authUser->can('ForceDelete:SampleIssue') && $sampleIssue->trashed() && ! $sampleIssue->isPosted()
            && $sampleIssue->status === SampleIssueStatus::Draft;
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:SampleIssue');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SampleIssue');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SampleIssue');
    }

    public function replicate(AuthUser $authUser, SampleIssue $sampleIssue): bool
    {
        return $authUser->can('Replicate:SampleIssue');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SampleIssue');
    }

    public function viewOwnTerritory(AuthUser $authUser, SampleIssue $sampleIssue): bool
    {
        return $authUser->can('ViewOwnTerritory:SampleIssue');
    }

    public function viewOwnOU(AuthUser $authUser, SampleIssue $sampleIssue): bool
    {
        return $authUser->can('ViewOwnOU:SampleIssue');
    }

    public function viewOwn(AuthUser $authUser, SampleIssue $sampleIssue): bool
    {
        return $authUser->can('ViewOwn:SampleIssue');
    }

    public function overrideApproval(AuthUser $authUser, SampleIssue $sampleIssue): bool
    {
        return $authUser->can('OverrideApproval:SampleIssue');
    }
}
