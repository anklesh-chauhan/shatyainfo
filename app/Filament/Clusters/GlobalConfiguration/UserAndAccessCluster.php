<?php

namespace App\Filament\Clusters\GlobalConfiguration;

use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;

class UserAndAccessCluster extends Cluster
{
    // protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $clusterBreadcrumb = 'Company & Access';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings & Administration';

    protected static ?string $navigationLabel = 'Company & Access';

    protected static ?int $navigationSort = 10;
}
