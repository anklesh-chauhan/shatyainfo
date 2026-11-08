<?php

namespace App\Filament\Clusters\HR;

use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Support\Icons\Heroicon;

class EmployeeManagementCluster extends Cluster
{
    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $clusterBreadcrumb = 'Employee Management';

    protected static string|\UnitEnum|null $navigationGroup = 'HR & Attendance';

    protected static ?string $navigationLabel = 'Employees & Organization';

    protected static ?int $navigationSort = 10;
}
