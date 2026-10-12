<?php

namespace App\Filament\Landlord\Pages;

use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class Dashboard extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected string $view = 'filament.landlord.pages.landlord-dashboard';
}
