<?php

namespace App\Filament\Clusters\GlobalConfiguration;

use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;

class OperationalConfigCluster extends Cluster
{
    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $clusterBreadcrumb = 'Operational Config';

    protected static string|\UnitEnum|null $navigationGroup = 'Global Configuration';

    protected static ?string $navigationLabel = 'Operational Config';

    protected static ?int $navigationSort = 10;
}
