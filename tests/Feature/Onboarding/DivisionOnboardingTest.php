<?php

use App\Filament\Pages\Onboarding;
use App\Models\City;
use App\Models\CityPinCode;
use App\Models\Country;
use App\Models\Organization;
use App\Models\OrganizationalUnit;
use App\Models\State;
use App\Models\Tenant;
use App\Models\Territory;
use App\Models\TypeMaster;
use App\Services\CompanyOnboardingService;
use App\Services\DataImport\DivisionImportTemplate;
use App\Services\DivisionOnboardingService;
use App\Services\TerritoryOnboardingService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(reportingUser('Setup Owner'));
    auth()->user()->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->organization = Organization::create(['name' => 'Division Company', 'metadata' => ['company_setup' => ['organization_saved' => true]]]);
    app()->instance(CompanyOnboardingService::class, new class extends CompanyOnboardingService
    {
        public function authorize(): void {}
    });
    $this->service = app(DivisionOnboardingService::class);
});

function divisionImportRows(): array
{
    return [['code' => 'DIV-WEST', 'name' => 'West Division', 'description' => ''], ['code' => 'DIV-SOUTH', 'name' => 'South Division', 'description' => '']];
}

function divisionTestTerritory(): array
{
    $country = Country::create(['name' => 'India']);
    $state = State::create(['name' => 'Maharashtra', 'country_id' => $country->id]);
    $city = City::create(['name' => 'Mumbai', 'state_id' => $state->id, 'country_id' => $country->id]);
    CityPinCode::create(['city_id' => $city->id, 'area_town' => 'Andheri', 'pin_code' => 400058]);

    return [['code' => 'WEST', 'name' => 'West', 'parent_code' => '', 'cities_and_areas' => 'Mumbai|Andheri|400058']];
}

it('previews imports and reuses divisions as ordinary organizational units', function () {
    expect($this->service->preview(divisionImportRows())[0]['action'])->toBe('Create');
    $this->assertDatabaseCount('organizational_units', 0);
    expect($this->service->import(divisionImportRows()))->toBe(2);
    expect(OrganizationalUnit::where('code', 'DIV-WEST')->sole()->typeMaster->name)->toBe('Division');
    expect($this->service->import(divisionImportRows()))->toBe(0);
    $rows = divisionTestTerritory();
    $rows[0]['division_codes'] = 'DIV-WEST;DIV-SOUTH';
    app(TerritoryOnboardingService::class)->import($rows);
    expect(Territory::where('code', 'WEST')->sole()->divisions()->count())->toBe(2);
});

it('rejects duplicates conflicts and codes belonging to another unit type atomically', function (string $case) {
    $rows = divisionImportRows();
    if ($case === 'duplicate') {
        $rows[1]['code'] = 'div-west';
    } else {
        $type = TypeMaster::create(['name' => $case === 'type' ? 'Department' : 'Division', 'typeable_type' => OrganizationalUnit::class]);
        OrganizationalUnit::create(['name' => $case === 'type' ? 'South Division' : 'Existing South', 'code' => 'DIV-SOUTH', 'type_master_id' => $type->id]);
    }
    expect(fn () => $this->service->import($rows))->toThrow(ValidationException::class);
    expect(OrganizationalUnit::where('code', 'DIV-WEST')->exists())->toBeFalse();
})->with(['duplicate', 'conflict', 'type']);

it('preserves a conflicting admin edit made after preview', function () {
    $preview = $this->service->preview(divisionImportRows());
    $type = TypeMaster::create(['name' => 'Division', 'typeable_type' => OrganizationalUnit::class]);
    OrganizationalUnit::create(['name' => 'Changed South', 'code' => 'DIV-SOUTH', 'type_master_id' => $type->id]);
    expect(fn () => $this->service->import($preview))->toThrow(ValidationException::class);
    expect(OrganizationalUnit::where('code', 'DIV-SOUTH')->sole()->name)->toBe('Changed South');
    expect(OrganizationalUnit::where('code', 'DIV-WEST')->exists())->toBeFalse();
});

it('saves the no-divisions preference and imports territories without division links', function () {
    app(CompanyOnboardingService::class)->saveOrganization(['name' => 'Company', 'email' => 'company@example.test', 'phone' => '9999999999', 'uses_divisions' => false]);
    expect(Organization::divisionsEnabled())->toBeFalse();
    expect(fn () => $this->service->import(divisionImportRows()))->toThrow(ValidationException::class);
    app(TerritoryOnboardingService::class)->import(divisionTestTerritory());
    expect(Territory::where('code', 'WEST')->sole()->divisions()->count())->toBe(0);
    expect($this->organization->fresh()->metadata['company_setup']['organization_saved'])->toBeTrue();
});

it('preserves existing division links when the company disables divisions', function () {
    $this->service->import(divisionImportRows());
    $rows = divisionTestTerritory();
    $rows[0]['division_codes'] = 'DIV-WEST';
    app(TerritoryOnboardingService::class)->import($rows);
    app(CompanyOnboardingService::class)->saveOrganization(['name' => 'Company', 'email' => 'company@example.test', 'phone' => '9999999999', 'uses_divisions' => false]);
    unset($rows[0]['division_codes']);
    app(TerritoryOnboardingService::class)->import($rows);
    expect(Territory::where('code', 'WEST')->sole()->divisions()->count())->toBe(1);
    expect(OrganizationalUnit::count())->toBe(2);
});

it('requires divisions again when the company enables them', function () {
    $rows = divisionTestTerritory();
    app(CompanyOnboardingService::class)->saveOrganization(['name' => 'Company', 'email' => 'company@example.test', 'phone' => '9999999999', 'uses_divisions' => true]);
    expect(fn () => app(TerritoryOnboardingService::class)->import($rows))->toThrow(ValidationException::class);
});

it('provides a blank workbook whose examples are never imported', function () {
    $path = sys_get_temp_dir().'/division-template-'.bin2hex(random_bytes(8)).'.xlsx';
    try {
        app(DivisionImportTemplate::class)->write($path);
        expect($this->service->readFile($path, true))->toBe([]);
    } finally {
        unlink($path);
    }
});

it('offers combined territory and division preview and persists the no-divisions choice', function () {
    divisionTestTerritory();
    config(['multitenancy.switch_tenant_tasks' => []]);
    app('db')->connection('tenant')->setPdo(app('db')->connection()->getPdo())->setReadPdo(app('db')->connection()->getPdo());
    $tenant = new Tenant;
    $tenant->forceFill(['id' => 99999, 'name' => 'Test', 'master_setup_status' => 'ready']);
    $tenant->makeCurrent();
    try {
        auth()->user()->assignRole(Role::findOrCreate('super_admin', 'tenant'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::test(Onboarding::class)->assertSet('step', 2)
            ->assertDontSee('Import divisions first')
            ->set('territoryFile', UploadedFile::fake()->createWithContent('territories.csv', "code,name,parent_code,cities_and_areas,division_codes,division_names\nWEST,West,,Mumbai|Andheri|400058,DIV-WEST,West Division\n"))
            ->call('previewTerritories')->assertHasNoErrors()->assertSee('West Division');
        $this->assertDatabaseCount('organizational_units', 0);
        $page->call('importTerritories')->assertHasNoErrors();
        $this->assertDatabaseCount('organizational_units', 1);
        $page->call('back')->set('organization.uses_divisions', 0)
            ->set('organization.email', 'company@example.test')->set('organization.phone', '9999999999')
            ->call('saveOrganization')->assertHasNoErrors()->assertSet('step', 2)
            ->assertSee('Your company does not use divisions')->assertDontSee('Import divisions first');
        Livewire::test(Onboarding::class)->assertSet('organization.uses_divisions', 0)->call('back')->assertSee('Your company does not use divisions');
    } finally {
        Tenant::forgetCurrent();
    }
});

it('validates saves previews and preserves the company logo during onboarding', function () {
    Storage::fake('public');
    config(['multitenancy.switch_tenant_tasks' => []]);
    app('db')->connection('tenant')->setPdo(app('db')->connection()->getPdo())->setReadPdo(app('db')->connection()->getPdo());
    $tenant = new Tenant;
    $tenant->forceFill(['id' => 99999, 'name' => 'Test', 'master_setup_status' => 'ready']);
    $tenant->makeCurrent();

    try {
        auth()->user()->assignRole(Role::findOrCreate('super_admin', 'tenant'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::test(Onboarding::class)->call('back')
            ->assertSee('Company logo (optional)')
            ->set('organization.email', 'company@example.test')
            ->set('organization.phone', '9999999999')
            ->set('companyLogo', UploadedFile::fake()->create('document.pdf', 10, 'application/pdf'))
            ->call('saveOrganization')->assertHasErrors(['companyLogo'])->assertSet('step', 1)
            ->set('companyLogo', UploadedFile::fake()->image('large.png')->size(2049))
            ->call('saveOrganization')->assertHasErrors(['companyLogo' => 'max'])
            ->set('companyLogo', UploadedFile::fake()->image('logo.png'))
            ->assertHasNoErrors()->assertSee('Company logo preview')
            ->call('saveOrganization')->assertHasNoErrors()->assertSet('step', 2)->assertSet('companyLogo', null);

        $path = $this->organization->fresh()->logo;
        expect($path)->toStartWith('organization-logos/');
        Storage::disk('public')->assertExists($path);
        $page->call('back')->assertSee(Storage::disk('public')->url($path))
            ->call('saveOrganization')->assertHasNoErrors();
        expect($this->organization->fresh()->logo)->toBe($path);

        Livewire::test(Onboarding::class)->call('back')
            ->assertSee(Storage::disk('public')->url($path))
            ->set('companyLogo', UploadedFile::fake()->image('replacement.png'))
            ->call('saveOrganization')->assertHasNoErrors();
        expect($this->organization->fresh()->logo)->not->toBe($path);
        Storage::disk('public')->assertExists($this->organization->fresh()->logo);
    } finally {
        Tenant::forgetCurrent();
    }
});

it('creates shared divisions once with multiple territories and safely repeats the import', function () {
    $rows = divisionTestTerritory();
    $rows[0]['division_codes'] = 'NEW-WEST;NEW-SOUTH';
    $rows[0]['division_names'] = 'New West;New South';
    $rows[] = [...$rows[0], 'code' => 'EAST', 'name' => 'East'];
    $service = app(TerritoryOnboardingService::class);
    $preview = $service->preview($rows);
    expect($preview[0]['division_plans'][0]['action'])->toBe('Create');
    $this->assertDatabaseCount('organizational_units', 0);
    expect($service->import($preview))->toBe(2);
    $this->assertDatabaseCount('organizational_units', 2);
    expect(Territory::where('code', 'WEST')->sole()->divisions()->count())->toBe(2);
    expect($service->import($rows))->toBe(0);
    $this->assertDatabaseCount('organizational_units', 2);
});

it('rejects conflicting and misaligned division definitions without creating records', function (string $case) {
    $rows = divisionTestTerritory();
    $rows[0]['division_codes'] = 'NEW-WEST';
    $rows[0]['division_names'] = 'New West';
    if ($case === 'alignment') {
        $rows[0]['division_codes'] = 'NEW-WEST;NEW-SOUTH';
    } elseif ($case === 'missing') {
        $rows[0]['division_names'] = '';
    } elseif ($case === 'location') {
        $rows[0]['cities_and_areas'] = 'Mumbai|Missing|400058';
    } else {
        $rows[] = [...$rows[0], 'code' => 'EAST', 'name' => 'East', 'division_names' => 'Conflicting West'];
    }
    expect(fn () => app(TerritoryOnboardingService::class)->import($rows))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('organizational_units', 0);
    $this->assertDatabaseCount('territories', 0);
})->with(['alignment', 'missing', 'location', 'conflict']);

it('rolls back newly created divisions when territory persistence fails', function () {
    $rows = divisionTestTerritory();
    $rows[0]['division_codes'] = 'NEW-WEST';
    $rows[0]['division_names'] = 'New West';
    Territory::creating(fn () => throw new RuntimeException('Simulated persistence failure'));
    try {
        expect(fn () => app(TerritoryOnboardingService::class)->import($rows))->toThrow(RuntimeException::class);
        $this->assertDatabaseCount('organizational_units', 0);
        $this->assertDatabaseCount('territories', 0);
    } finally {
        Territory::flushEventListeners();
        Territory::clearBootedModels();
    }
});

it('revalidates a division created by an admin after territory preview', function () {
    $rows = divisionTestTerritory();
    $rows[0]['division_codes'] = 'NEW-WEST';
    $rows[0]['division_names'] = 'New West';
    $service = app(TerritoryOnboardingService::class);
    $preview = $service->preview($rows);
    $this->service->import([['code' => 'NEW-WEST', 'name' => 'Different West']]);
    expect(fn () => $service->import($preview))->toThrow(ValidationException::class);
    expect(OrganizationalUnit::where('code', 'NEW-WEST')->sole()->name)->toBe('Different West');
    $this->assertDatabaseCount('territories', 0);
});
