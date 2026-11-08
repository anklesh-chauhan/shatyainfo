<?php

namespace App\Filament\Clusters\HR;

use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;

class AttendanceCluster extends Cluster
{
    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $clusterBreadcrumb = 'Attendance';

    protected static string|\UnitEnum|null $navigationGroup = 'HR & Attendance';

    protected static ?string $navigationLabel = 'Attendance';

    protected static ?int $navigationSort = 20;
}
