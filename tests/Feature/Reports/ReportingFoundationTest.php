<?php

use App\Filament\Pages\Reports\ReportsOverview;
use App\Models\SalesDcr;
use App\Models\Territory;
use App\Services\Reports\DashboardMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

it('returns zero dashboard metrics when nothing exists', function () {
    $user = reportingUser('Empty');

    $metrics = app(DashboardMetrics::class)->forUser($user);

    expect($metrics->visitsToday)->toBe(0)
        ->and($metrics->pendingDcrs)->toBe(0)
        ->and($metrics->pendingApprovals)->toBe(0)
        ->and($metrics->punchedInToday)->toBe(0);
});

it('counts only visible pending DCRs', function () {
    $north = Territory::query()->create([
        'name' => 'North',
        'code' => fake()->unique()->bothify('T###'),
    ]);
    $south = Territory::query()->create([
        'name' => 'South',
        'code' => fake()->unique()->bothify('T###'),
    ]);

    $rep = reportingUser('NorthRep');
    $other = reportingUser('SouthRep');

    $this->actingAs($rep);

    SalesDcr::forceCreate([
        'dcr_date' => today(),
        'user_id' => $rep->id,
        'approval_status' => 'draft',
        'territory_id' => $north->id,
        'created_by' => $rep->id,
        'updated_by' => $rep->id,
    ]);

    SalesDcr::forceCreate([
        'dcr_date' => today(),
        'user_id' => $other->id,
        'approval_status' => 'pending',
        'territory_id' => $south->id,
        'created_by' => $other->id,
        'updated_by' => $other->id,
    ]);

    SalesDcr::forceCreate([
        'dcr_date' => today(),
        'user_id' => $rep->id,
        'approval_status' => 'approved',
        'territory_id' => $north->id,
        'created_by' => $rep->id,
        'updated_by' => $rep->id,
    ]);

    $metrics = app(DashboardMetrics::class)->forUser($rep);

    expect($metrics->pendingDcrs)->toBe(1);

    Permission::firstOrCreate([
        'name' => 'AccessAllRecords',
        'guard_name' => 'web',
    ]);
    $rep->givePermissionTo('AccessAllRecords');
    $rep->refresh();

    $metrics = app(DashboardMetrics::class)->forUser($rep);

    expect($metrics->pendingDcrs)->toBe(2);
});

it('renders the reports overview catalog', function () {
    $user = reportingUser('Overview');

    $this->actingAs($user);

    $page = livewire(ReportsOverview::class);

    $page->assertSuccessful()
        ->assertSee('Field activity')
        ->assertSee('Samples and stock')
        ->assertSee('Sales')
        ->assertSee('HR')
        ->assertSee('Doctor Sample Ledger')
        ->assertSee('DCR register')
        ->assertSee('SGIP compliance')
        ->assertSee('Lead pipeline')
        ->assertSee('Overdue follow-ups')
        ->assertSee('Attendance register')
        ->assertSee('Leave ledger')
        ->assertSeeHtml('fi-page-sub-navigation-sidebar-ctn')
        ->assertSeeHtml('fi-subnav-collapse-btn')
        ->assertSeeHtml('fi-subnav-expand-btn');

    expect($page->instance()->getCatalog())->toHaveCount(15)
        ->and(array_column($page->instance()->getCatalogGroups(), 'label'))->toBe([
            'Field activity',
            'Samples and stock',
            'Sales',
            'HR',
        ]);
});
