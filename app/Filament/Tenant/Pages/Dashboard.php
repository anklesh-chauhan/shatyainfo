<?php

namespace App\Filament\Tenant\Pages;

use App\Filament\Widgets\CurrentDatabase;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;

class Dashboard extends BaseDashboard
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected string $view = 'filament.pages.tenant-dashboard';

    public function getWidgets(): array
    {
        return [
            CurrentDatabase::class,
            // other widgets...
        ];
    }
}
