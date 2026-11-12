<?php

namespace App\Filament\Clusters\GlobalConfiguration;

use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;

class OperationalConfigCluster extends Cluster
{
    // protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $clusterBreadcrumb = 'Approvals & System';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings & Administration';

    protected static ?string $navigationLabel = 'Approvals & System';

    protected static ?int $navigationSort = 70;
}
