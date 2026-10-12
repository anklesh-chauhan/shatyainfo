<?php

namespace App\Filament\Clusters\GlobalConfiguration;

use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;

class SalesMarketingConfigurationCluster extends Cluster
{
    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?string $clusterBreadcrumb = 'Sales & Marketing';

    protected static string|\UnitEnum|null $navigationGroup = 'Global Configuration';

    protected static ?string $navigationLabel = 'Sales & Marketing';

    protected static ?int $navigationSort = 10;
}
