<?php

namespace App\Filament\Tenant\Pages;

use App\Filament\Widgets\CurrentDatabase;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class Dashboard extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected string $view = 'filament.pages.tenant-dashboard';

    public static function getWidgets(): array
    {
        return [
            CurrentDatabase::class,
            // other widgets...
        ];
    }
}
