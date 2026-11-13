<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\AccountReportingMaster;
use Illuminate\Auth\Access\HandlesAuthorization;

class AccountReportingMasterPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:AccountReportingMaster');
    }

    public function view(AuthUser $authUser, AccountReportingMaster $accountReportingMaster): bool
    {
        return $authUser->can('View:AccountReportingMaster');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:AccountReportingMaster');
    }

    public function update(AuthUser $authUser, AccountReportingMaster $accountReportingMaster): bool
    {
        return $authUser->can('Update:AccountReportingMaster');
    }

    public function delete(AuthUser $authUser, AccountReportingMaster $accountReportingMaster): bool
    {
        return $authUser->can('Delete:AccountReportingMaster');
    }

    public function restore(AuthUser $authUser, AccountReportingMaster $accountReportingMaster): bool
    {
        return $authUser->can('Restore:AccountReportingMaster');
    }

    public function forceDelete(AuthUser $authUser, AccountReportingMaster $accountReportingMaster): bool
    {
        return $authUser->can('ForceDelete:AccountReportingMaster');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:AccountReportingMaster');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:AccountReportingMaster');
    }

    public function replicate(AuthUser $authUser, AccountReportingMaster $accountReportingMaster): bool
    {
        return $authUser->can('Replicate:AccountReportingMaster');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:AccountReportingMaster');
    }

    public function viewOwnTerritory(AuthUser $authUser, AccountReportingMaster $accountReportingMaster): bool
    {
        return $authUser->can('ViewOwnTerritory:AccountReportingMaster');
    }

    public function viewOwnOU(AuthUser $authUser, AccountReportingMaster $accountReportingMaster): bool
    {
        return $authUser->can('ViewOwnOU:AccountReportingMaster');
    }

    public function viewOwn(AuthUser $authUser, AccountReportingMaster $accountReportingMaster): bool
    {
        return $authUser->can('ViewOwn:AccountReportingMaster');
    }

    public function overrideApproval(AuthUser $authUser, AccountReportingMaster $accountReportingMaster): bool
    {
        return $authUser->can('OverrideApproval:AccountReportingMaster');
    }

}