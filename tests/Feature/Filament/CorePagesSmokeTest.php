<?php

use App\Filament\Pages\MyApprovals;
use App\Filament\Pages\TodaysTour;
use App\Http\Middleware\PromptCompanySetup;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

it('renders today\'s visits for an authenticated representative', function () {
    $this->actingAs(grantPagePermissions(reportingUser('TodayTour'), 'View:TodaysTour'));

    livewire(TodaysTour::class)
        ->assertSuccessful();
});

it('renders my approvals with an empty inbox', function () {
    $this->actingAs(grantPagePermissions(reportingUser('MyApprovals'), 'View:MyApprovals'));

    livewire(MyApprovals::class)
        ->assertSuccessful();
});

it('opens the first eligible admin page for users without dashboard access', function () {
    $this->withoutMiddleware(PromptCompanySetup::class);
    $this->actingAs(grantPagePermissions(reportingUser('Tour Only'), 'View:TodaysTour'));

    $this->get(route('filament.admin.pages.dashboard'))
        ->assertRedirect(TodaysTour::getUrl(panel: 'admin'));
});
