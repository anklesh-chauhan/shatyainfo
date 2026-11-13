<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\SecondarySalesStatement;
use Illuminate\Auth\Access\HandlesAuthorization;

class SecondarySalesStatementPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SecondarySalesStatement');
    }

    public function view(AuthUser $authUser, SecondarySalesStatement $secondarySalesStatement): bool
    {
        return $authUser->can('View:SecondarySalesStatement');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SecondarySalesStatement');
    }

    public function update(AuthUser $authUser, SecondarySalesStatement $secondarySalesStatement): bool
    {
        return $authUser->can('Update:SecondarySalesStatement');
    }

    public function delete(AuthUser $authUser, SecondarySalesStatement $secondarySalesStatement): bool
    {
        return $authUser->can('Delete:SecondarySalesStatement');
    }

    public function restore(AuthUser $authUser, SecondarySalesStatement $secondarySalesStatement): bool
    {
        return $authUser->can('Restore:SecondarySalesStatement');
    }

    public function forceDelete(AuthUser $authUser, SecondarySalesStatement $secondarySalesStatement): bool
    {
        return $authUser->can('ForceDelete:SecondarySalesStatement');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SecondarySalesStatement');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SecondarySalesStatement');
    }

    public function replicate(AuthUser $authUser, SecondarySalesStatement $secondarySalesStatement): bool
    {
        return $authUser->can('Replicate:SecondarySalesStatement');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SecondarySalesStatement');
    }

    public function viewOwnTerritory(AuthUser $authUser, SecondarySalesStatement $secondarySalesStatement): bool
    {
        return $authUser->can('ViewOwnTerritory:SecondarySalesStatement');
    }

    public function viewOwnOU(AuthUser $authUser, SecondarySalesStatement $secondarySalesStatement): bool
    {
        return $authUser->can('ViewOwnOU:SecondarySalesStatement');
    }

    public function viewOwn(AuthUser $authUser, SecondarySalesStatement $secondarySalesStatement): bool
    {
        return $authUser->can('ViewOwn:SecondarySalesStatement');
    }

    public function overrideApproval(AuthUser $authUser, SecondarySalesStatement $secondarySalesStatement): bool
    {
        return $authUser->can('OverrideApproval:SecondarySalesStatement');
    }

}