<?php

namespace App\Filament\Clusters\GlobalConfiguration;

use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;

class LeadConfigurationCluster extends Cluster
{
    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedFunnel;

    protected static ?string $clusterBreadcrumb = 'Lead Config';

    protected static string|\UnitEnum|null $navigationGroup = 'Global Configuration';

    protected static ?string $navigationLabel = 'Lead Config';

    protected static ?int $navigationSort = 10;
}
