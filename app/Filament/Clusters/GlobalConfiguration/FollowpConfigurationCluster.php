<?php

namespace App\Filament\Clusters\GlobalConfiguration;

use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;

class FollowpConfigurationCluster extends Cluster
{
    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static ?string $clusterBreadcrumb = 'Followup Config';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings & Administration';

    protected static ?string $navigationLabel = 'Followup Config';

    protected static ?int $navigationSort = 10;
}
