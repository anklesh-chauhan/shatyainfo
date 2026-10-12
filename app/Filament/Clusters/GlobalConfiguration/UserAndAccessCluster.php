<?php

namespace App\Filament\Clusters\GlobalConfiguration;

use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;

class UserAndAccessCluster extends Cluster
{
    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $clusterBreadcrumb = 'User & Access';

    protected static string|\UnitEnum|null $navigationGroup = 'Global Configuration';

    protected static ?string $navigationLabel = 'User & Access';

    protected static ?int $navigationSort = 10;
}
