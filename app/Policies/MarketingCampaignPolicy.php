<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\MarketingCampaign;
use Illuminate\Auth\Access\HandlesAuthorization;

class MarketingCampaignPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:MarketingCampaign');
    }

    public function view(AuthUser $authUser, MarketingCampaign $marketingCampaign): bool
    {
        return $authUser->can('View:MarketingCampaign');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:MarketingCampaign');
    }

    public function update(AuthUser $authUser, MarketingCampaign $marketingCampaign): bool
    {
        return $authUser->can('Update:MarketingCampaign');
    }

    public function delete(AuthUser $authUser, MarketingCampaign $marketingCampaign): bool
    {
        return $authUser->can('Delete:MarketingCampaign');
    }

    public function restore(AuthUser $authUser, MarketingCampaign $marketingCampaign): bool
    {
        return $authUser->can('Restore:MarketingCampaign');
    }

    public function forceDelete(AuthUser $authUser, MarketingCampaign $marketingCampaign): bool
    {
        return $authUser->can('ForceDelete:MarketingCampaign');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:MarketingCampaign');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:MarketingCampaign');
    }

    public function replicate(AuthUser $authUser, MarketingCampaign $marketingCampaign): bool
    {
        return $authUser->can('Replicate:MarketingCampaign');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:MarketingCampaign');
    }

    public function viewOwnTerritory(AuthUser $authUser, MarketingCampaign $marketingCampaign): bool
    {
        return $authUser->can('ViewOwnTerritory:MarketingCampaign');
    }

    public function viewOwnOU(AuthUser $authUser, MarketingCampaign $marketingCampaign): bool
    {
        return $authUser->can('ViewOwnOU:MarketingCampaign');
    }

    public function viewOwn(AuthUser $authUser, MarketingCampaign $marketingCampaign): bool
    {
        return $authUser->can('ViewOwn:MarketingCampaign');
    }

    public function overrideApproval(AuthUser $authUser, MarketingCampaign $marketingCampaign): bool
    {
        return $authUser->can('OverrideApproval:MarketingCampaign');
    }

}