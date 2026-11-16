<?php

use App\Filament\Exports\Reports\DcrRegisterExporter;
use App\Filament\Pages\Reports\ReportsOverview;
use App\Models\SalesDcr;
use App\Models\Territory;
use App\Services\Reports\DashboardMetrics;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

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

    $this->actingAs(grantPagePermissions($user, 'View:ReportsOverview'));

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

it('requires the report page permission independently of record visibility', function () {
    $user = reportingUser('Report Page Gate');
    $user->givePermissionTo(Permission::findOrCreate('AccessAllRecords', 'web'));
    $this->actingAs($user);

    livewire(ReportsOverview::class)->assertForbidden();

    grantPagePermissions($user, 'View:ReportsOverview');
    $this->actingAs($user->fresh());
    livewire(ReportsOverview::class)->assertSuccessful();
    expect(collect((new ReportsOverview)->getCatalog())->firstWhere('label', 'DCR register')['url'])->toBeNull();
});

it('denies downloading an existing report export when permission is revoked', function () {
    $owner = grantPagePermissions(reportingUser('Report Exporter'), 'View:DcrRegister', 'Export:Reports');
    $recordScope = Role::findOrCreate('Report Test Scope', 'web');
    $recordScope->givePermissionTo(Permission::findOrCreate('AccessAllRecords', 'web'));
    $owner->assignRole($recordScope);
    Storage::fake('local');
    $export = Export::query()->create([
        'exporter' => DcrRegisterExporter::class,
        'user_id' => $owner->id,
        'file_disk' => 'local',
        'file_name' => 'dcr-register',
        'total_rows' => 1,
    ]);
    $url = route('filament.exports.download', ['export' => $export, 'format' => 'csv']);

    Storage::disk('local')->put($export->getFileDirectory().'/headers.csv', "Report\n");
    $this->actingAs($owner)->get($url)->assertSuccessful()->assertDownload('dcr-register.csv');

    $other = reportingUser('Other Exporter')->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($other);
    $this->get($url)->assertForbidden();

    $owner->removeRole($recordScope);
    $this->actingAs($owner->fresh())->get($url)->assertForbidden();

    $owner->revokePermissionTo('Export:Reports');
    $this->actingAs($owner->fresh());
    $this->get($url)->assertForbidden();
});
