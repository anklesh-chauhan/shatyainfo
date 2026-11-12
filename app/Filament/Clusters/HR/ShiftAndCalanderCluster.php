<?php

namespace App\Filament\Clusters\HR;

use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;

class ShiftAndCalanderCluster extends Cluster
{
    // protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $clusterBreadcrumb = 'HR Setup';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings & Administration';

    protected static ?string $navigationLabel = 'HR Setup';

    protected static ?int $navigationSort = 60;
}
