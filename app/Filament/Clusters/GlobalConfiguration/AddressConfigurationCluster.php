<?php

namespace App\Filament\Clusters\GlobalConfiguration;

use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;

class AddressConfigurationCluster extends Cluster
{
    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?string $clusterBreadcrumb = 'Address Config';

    protected static string|\UnitEnum|null $navigationGroup = 'Global Configuration';

    protected static ?string $navigationLabel = 'Address Config';

    protected static ?int $navigationSort = 10;
}
