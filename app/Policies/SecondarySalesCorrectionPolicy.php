<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\SecondarySalesCorrection;
use Illuminate\Auth\Access\HandlesAuthorization;

class SecondarySalesCorrectionPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SecondarySalesCorrection');
    }

    public function view(AuthUser $authUser, SecondarySalesCorrection $secondarySalesCorrection): bool
    {
        return $authUser->can('View:SecondarySalesCorrection');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SecondarySalesCorrection');
    }

    public function update(AuthUser $authUser, SecondarySalesCorrection $secondarySalesCorrection): bool
    {
        return $authUser->can('Update:SecondarySalesCorrection');
    }

    public function delete(AuthUser $authUser, SecondarySalesCorrection $secondarySalesCorrection): bool
    {
        return $authUser->can('Delete:SecondarySalesCorrection');
    }

    public function restore(AuthUser $authUser, SecondarySalesCorrection $secondarySalesCorrection): bool
    {
        return $authUser->can('Restore:SecondarySalesCorrection');
    }

    public function forceDelete(AuthUser $authUser, SecondarySalesCorrection $secondarySalesCorrection): bool
    {
        return $authUser->can('ForceDelete:SecondarySalesCorrection');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SecondarySalesCorrection');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SecondarySalesCorrection');
    }

    public function replicate(AuthUser $authUser, SecondarySalesCorrection $secondarySalesCorrection): bool
    {
        return $authUser->can('Replicate:SecondarySalesCorrection');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SecondarySalesCorrection');
    }

    public function viewOwnTerritory(AuthUser $authUser, SecondarySalesCorrection $secondarySalesCorrection): bool
    {
        return $authUser->can('ViewOwnTerritory:SecondarySalesCorrection');
    }

    public function viewOwnOU(AuthUser $authUser, SecondarySalesCorrection $secondarySalesCorrection): bool
    {
        return $authUser->can('ViewOwnOU:SecondarySalesCorrection');
    }

    public function viewOwn(AuthUser $authUser, SecondarySalesCorrection $secondarySalesCorrection): bool
    {
        return $authUser->can('ViewOwn:SecondarySalesCorrection');
    }

    public function overrideApproval(AuthUser $authUser, SecondarySalesCorrection $secondarySalesCorrection): bool
    {
        return $authUser->can('OverrideApproval:SecondarySalesCorrection');
    }

}