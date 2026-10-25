<?php

use App\Filament\Pages\DataImport;
use App\Filament\Pages\FieldTravelImport;
use App\Filament\Pages\Onboarding;
use App\Filament\Resources\Departments\DepartmentResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\Country;
use App\Models\Department;
use App\Models\Organization;
use App\Models\State;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DataImport\ResourceCsvImporter;
use App\Services\DataImport\ResourceImportCatalog;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(reportingUser('DataImporter'));
});

it('lists importable modules and skips accounts and tenants', function () {
    $catalog = app(ResourceImportCatalog::class);
    $models = collect($catalog->all())->pluck('modelClass');

    expect($models->count())->toBeGreaterThan(30)
        ->and($models)->toContain(Department::class, Country::class, State::class, Organization::class)
        ->and($models)->not->toContain(User::class, Tenant::class);

    $state = $catalog->findByModel(State::class);

    expect($state)->not->toBeNull()
        ->and(collect($state->columns)->pluck('header'))->toContain('name', 'country')
        ->and($state->matchAttribute)->toBe('name');
});

it('creates and updates records matched by name', function () {
    $module = app(ResourceImportCatalog::class)->findByModel(Department::class);
    $importer = app(ResourceCsvImporter::class);

    $created = $importer->import($module, dataImportCsv("name\nCardiology\n"));

    expect($created->created)->toBe(1)
        ->and($created->updated)->toBe(0)
        ->and(Department::query()->where('name', 'Cardiology')->exists())->toBeTrue();

    $updated = $importer->import($module, dataImportCsv("name\nCardiology\n"));

    expect($updated->created)->toBe(0)
        ->and($updated->updated)->toBe(1)
        ->and(Department::query()->count())->toBe(1);
});

it('resolves related records by name and reports a missing one', function () {
    Country::query()->create(['name' => 'India']);

    $module = app(ResourceImportCatalog::class)->findByModel(State::class);
    $importer = app(ResourceCsvImporter::class);

    $imported = $importer->import($module, dataImportCsv("name,country,gst_code\nMaharashtra,India,27\nGujarat,Nowhere,24\n"));

    $state = State::query()->where('name', 'Maharashtra')->first();

    expect($imported->created)->toBe(1)
        ->and($imported->failed)->toHaveCount(1)
        ->and($imported->failed[0]['message'])->toContain('Nowhere')
        ->and($state)->not->toBeNull()
        ->and($state->country->name)->toBe('India')
        ->and($state->gst_code)->toBe('27')
        ->and(State::query()->where('name', 'Gujarat')->exists())->toBeFalse();
});

it('imports an organization and the parent named in the file', function () {
    $module = app(ResourceImportCatalog::class)->findByModel(Organization::class);
    $importer = app(ResourceCsvImporter::class);

    $result = $importer->import($module, dataImportCsv(<<<'CSV'
name,email,parent_organization
Acme West,west@acme.test,Acme Group
Acme Group,group@acme.test,
CSV));

    $west = Organization::query()->where('name', 'Acme West')->first();
    $group = Organization::query()->where('name', 'Acme Group')->first();

    expect($result->failed)->toBe([])
        ->and($west)->not->toBeNull()
        ->and($group)->not->toBeNull()
        ->and($west->parent_organization_id)->toBe($group->id)
        ->and($group->email)->toBe('group@acme.test')
        ->and(Organization::query()->count())->toBe(2);
});

it('rejects a record that references itself', function () {
    $module = app(ResourceImportCatalog::class)->findByModel(Organization::class);

    $result = app(ResourceCsvImporter::class)->import($module, dataImportCsv("name,parent_organization\nAcme,Acme\n"));

    expect($result->created)->toBe(0)
        ->and($result->failed[0]['message'])->toContain('cannot reference itself')
        ->and(Organization::query()->count())->toBe(0);
});

it('builds an example csv for the selected module', function () {
    $module = app(ResourceImportCatalog::class)->find(DepartmentResource::class);

    expect(app(ResourceCsvImporter::class)->exampleCsv($module))->toContain('name');
});

it('shows the data import page', function () {
    withDataImportTenant(function (): void {
        livewire(DataImport::class)
            ->assertSee('Onboarding data imports')
            ->assertSee('Module')
            ->assertSee('Territories & Divisions')
            ->assertSee('Opening Leave Balances')
            ->assertSee('Field Routes & Historical Travel')
            ->assertSet('data.resource', EmployeeResource::class);
    });
});

it('rejects generic modules even when the request state is manipulated', function () {
    withDataImportTenant(function (): void {
        livewire(DataImport::class)
            ->set('data.resource', DepartmentResource::class)
            ->call('import')->assertForbidden();
        expect(Department::query()->count())->toBe(0);
    });
});

it('offers historical travel in the shared embedded importer and resets mode state', function () {
    withDataImportTenant(function (): void {
        livewire(FieldTravelImport::class, ['embedded' => true])
            ->assertSee('Historical travel segments (optional)')
            ->set('historical', true)
            ->assertSee('editable daily call reports')
            ->call('downloadTemplate')->assertFileDownloaded('historical-travel.xlsx')
            ->set('historical', false)
            ->assertSet('file', null)->assertSet('preview', [])->assertSet('status', '')
            ->call('downloadTemplate')->assertFileDownloaded('patches-and-fares.xlsx');
    });
});

it('redirects the retired field travel page to data import', function () {
    withDataImportTenant(function (): void {
        expect(FieldTravelImport::shouldRegisterNavigation())->toBeFalse();
        livewire(FieldTravelImport::class)
            ->assertRedirect(DataImport::getUrl(['module' => 'field_routes'], panel: 'admin'));
    });
});

it('renders account counts when opening and refreshing the embedded account import', function () {
    withDataImportTenant(function (): void {
        livewire(Onboarding::class, ['importSection' => 'accounts'])
            ->assertSee('0 accounts saved')
            ->call('$refresh')
            ->assertSee('0 accounts saved');
    });
});

it('offers only onboarding import groups and rejects unknown groups', function () {
    withDataImportTenant(function (): void {
        expect(array_keys((new DataImport)->importOptions()))->toBe(['territories', 'employees', 'accounts', 'field_routes', 'products', 'hr']);
        livewire(DataImport::class)->set('importType', 'departments')->assertStatus(422);
    });
});

function withDataImportTenant(Closure $callback): void
{
    config(['multitenancy.switch_tenant_tasks' => []]);
    app('db')->connection('tenant')->setPdo(app('db')->connection()->getPdo())->setReadPdo(app('db')->connection()->getPdo());
    $tenant = new Tenant;
    $tenant->forceFill(['id' => 99999, 'name' => 'Test', 'master_setup_status' => 'ready']);
    $tenant->makeCurrent();
    try {
        auth()->user()->assignRole(Role::findOrCreate('super_admin', 'tenant'));
        Organization::create(['name' => 'Import Company', 'metadata' => ['company_setup' => ['organization_saved' => true]]]);
        $callback();
    } finally {
        Tenant::forgetCurrent();
    }
}

function dataImportCsv(string $contents): string
{
    $path = Storage::disk('local')->path('data-imports/'.str()->uuid().'.csv');

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }

    file_put_contents($path, $contents);

    return $path;
}
