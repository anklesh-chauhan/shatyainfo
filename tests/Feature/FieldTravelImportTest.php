<?php

use App\Filament\Pages\FieldTravelImport as ImportPage;
use App\Filament\Resources\TravelSegments\Pages\CreateTravelSegment;
use App\Jobs\ProcessFieldTravelImport;
use App\Models\AccountMaster;
use App\Models\Address;
use App\Models\City;
use App\Models\CityPinCode;
use App\Models\Country;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Patch;
use App\Models\SalesDcr;
use App\Models\StandardFareChart;
use App\Models\State;
use App\Models\Tenant;
use App\Models\Territory;
use App\Models\TravelSegment;
use App\Models\TypeMaster;
use App\Services\DataImport\FieldTravelImport;
use App\Services\DataImport\FieldTravelTemplate;
use App\Services\Travel\TravelSegmentValidation;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

afterEach(function () {
    Tenant::forgetCurrent();
});

function useFieldTravelImportTenant(): void
{
    config(['multitenancy.switch_tenant_tasks' => []]);
    app('db')->connection('tenant')->setPdo(app('db')->connection()->getPdo())->setReadPdo(app('db')->connection()->getPdo());
    $tenant = new Tenant;
    $tenant->forceFill(['id' => 99999, 'name' => 'Test', 'master_setup_status' => 'ready']);
    $tenant->makeCurrent();
    auth()->user()->assignRole(Role::findOrCreate('super_admin', 'tenant'));
}

beforeEach(function () {
    $this->actingAs(reportingUser('Travel Import Owner'));
    auth()->user()->assignRole(Role::findOrCreate('super_admin', 'web'));
    foreach (['Patch', 'StandardFareChart', 'TravelSegment'] as $model) {
        foreach (['Create', 'Update', 'ViewAny', 'View'] as $action) {
            auth()->user()->givePermissionTo(Permission::findOrCreate($action.':'.$model, 'web'));
        }
    }
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Organization::create(['name' => 'Travel Company']);
    $country = Country::create(['name' => 'India']);
    $state = State::create(['name' => 'Gujarat', 'country_id' => $country->id]);
    $city = City::create(['name' => 'Ahmedabad', 'state_id' => $state->id, 'country_id' => $country->id]);
    $this->from = CityPinCode::create(['city_id' => $city->id, 'area_town' => 'Navrangpura', 'pin_code' => 380009]);
    $this->to = CityPinCode::create(['city_id' => $city->id, 'area_town' => 'Paldi', 'pin_code' => 380007]);
    $this->territory = Territory::create(['name' => 'West', 'code' => 'TERR-01']);
    $this->territory->cityPinCodes()->attach([$this->from->id, $this->to->id]);
    $this->service = app(FieldTravelImport::class);
    Http::preventStrayRequests();
});

function fieldTravelRows(): array
{
    return [
        'Patches' => [['row' => 2, 'data' => array_combine(FieldTravelTemplate::COLUMNS['Patches'], ['PATCH-01', 'Central Route', 'TERR-01', '', '', '', '', ''])]],
        'Standard Fares' => [['row' => 2, 'data' => array_combine(FieldTravelTemplate::COLUMNS['Standard Fares'], ['TERR-01', 'PATCH-01', 'Ahmedabad', 'Navrangpura', '380009', 'Ahmedabad', 'Paldi', '380007', '12', '100', '', '1'])]],
    ];
}

it('previews without writes and imports patches and fares together without duplicates', function () {
    $rows = fieldTravelRows();
    $preview = $this->service->preview($rows);
    $this->assertSame([], $preview['errors']);
    expect(Patch::count())->toBe(0);
    expect($this->service->import($rows, $preview['fingerprint'])['Create'])->toBe(2);
    expect(StandardFareChart::first()->patch_id)->toBe(Patch::first()->id);
    $preview = $this->service->preview($rows);
    $this->assertSame([], $preview['errors']);
    expect($this->service->import($rows, $preview['fingerprint'])['Unchanged'])->toBe(2);
    expect(StandardFareChart::count())->toBe(1);
    Http::assertNothingSent();
});

it('rejects reversed duplicate fares and invalid location without saving patches', function () {
    $rows = fieldTravelRows();
    $reverse = $rows['Standard Fares'][0];
    $reverse['row'] = 3;
    foreach (['city', 'area', 'pin_code'] as $part) {
        [$reverse['data']['from_'.$part], $reverse['data']['to_'.$part]] = [$reverse['data']['to_'.$part], $reverse['data']['from_'.$part]];
    }
    $rows['Standard Fares'][] = $reverse;
    expect($this->service->preview($rows)['errors'])->not->toBeEmpty();
    expect(fn () => $this->service->import($rows, 'invalid'))->toThrow(ValidationException::class);
    expect(Patch::count())->toBe(0);
    $rows = fieldTravelRows();
    $rows['Standard Fares'][0]['data']['from_area'] = 'Missing';
    expect($this->service->preview($rows)['errors'])->not->toBeEmpty();
});

it('rejects concurrent record changes after preview', function () {
    $rows = fieldTravelRows();
    $preview = $this->service->preview($rows);
    $this->service->import($rows, $preview['fingerprint']);
    $preview = $this->service->preview($rows);
    Patch::first()->update(['description' => 'Changed by another administrator']);
    expect(fn () => $this->service->import($rows, $preview['fingerprint']))->toThrow(ValidationException::class);
    expect(Patch::first()->description)->toBe('Changed by another administrator');
});

it('rolls back patches when saving a fare fails', function () {
    $rows = fieldTravelRows();
    $preview = $this->service->preview($rows);
    StandardFareChart::creating(fn () => throw new RuntimeException('Simulated failure'));
    try {
        expect(fn () => $this->service->import($rows, $preview['fingerprint']))->toThrow(RuntimeException::class);
        expect(Patch::count())->toBe(0);
        expect(FieldTravelImport::isRunning())->toBeFalse();
    } finally {
        StandardFareChart::flushEventListeners();
        StandardFareChart::clearBootedModels();
    }
});

it('imports customer sequence and distance without generating placeholder fares', function () {
    $customerType = TypeMaster::create(['name' => 'Customer', 'typeable_type' => AccountMaster::class]);
    $customer = AccountMaster::create(['name' => 'Customer One', 'account_code' => 'CUST-001', 'owner_id' => auth()->id(), 'type_master_id' => $customerType->id]);
    Address::create(['addressable_id' => $customer->id, 'addressable_type' => AccountMaster::class, 'street' => 'Main road', 'area_town_id' => $this->from->id, 'pin_code' => $this->from->pin_code, 'is_primary' => true]);
    $rows = fieldTravelRows();
    unset($rows['Standard Fares']);
    $rows['Patch Customers'] = [['row' => 2, 'data' => ['patch_code' => 'PATCH-01', 'customer_code' => $customer->account_code, 'sequence_no' => '1', 'distance_to_next_km' => '12']]];
    $preview = $this->service->preview($rows);
    $this->assertSame([], $preview['errors']);
    expect($this->service->import($rows, $preview['fingerprint'])['Create'])->toBe(2);
    expect(Patch::first()->companies()->first()->pivot->distance_km)->toBe('12.00');
    expect(StandardFareChart::count())->toBe(0);
    $preview = $this->service->preview($rows);
    $this->assertSame([], $preview['errors']);
    expect($this->service->import($rows, $preview['fingerprint'])['Unchanged'])->toBe(2);
    $rows['Patch Customers'][] = $rows['Patch Customers'][0];
    expect($this->service->preview($rows)['errors'])->not->toBeEmpty();
});

it('denies imports without model permission', function () {
    $this->actingAs(reportingUser('Unprivileged Importer'));
    expect($this->service->preview(fieldTravelRows())['errors'])->not->toBeEmpty();
    expect(ImportPage::canAccess())->toBeFalse();
    expect(Patch::count())->toBe(0);
});

it('imports historical segments idempotently and rejects locked reports', function () {
    $employee = Employee::withoutEvents(fn () => Employee::create(['employee_id' => 'EMP-001', 'first_name' => 'Test', 'last_name' => 'Person', 'login_id' => auth()->id(), 'email' => 'history@example.test', 'mobile_number' => '9000000001']));
    $dcr = SalesDcr::create(['user_id' => auth()->id(), 'dcr_date' => '2026-01-15', 'territory_id' => $this->territory->id, 'approval_status' => 'draft']);
    $rows = ['Travel Segments' => [['row' => 2, 'data' => array_combine(FieldTravelTemplate::COLUMNS['Travel Segments'], ['LEGACY-001', 'EMP-001', '2026-01-15', '', '', '', 'Ahmedabad', 'Navrangpura', '380009', 'Ahmedabad', 'Paldi', '380007', '', '12'])]]];
    $preview = $this->service->preview($rows);
    $this->assertSame([], $preview['errors']);
    $this->service->import($rows, $preview['fingerprint']);
    $preview = $this->service->preview($rows);
    expect($this->service->import($rows, $preview['fingerprint'])['Unchanged'])->toBe(1);
    expect(TravelSegment::count())->toBe(1);
    expect(TravelSegment::first()->is_auto_generated)->toBeFalse();
    $dcr->forceFill(['approval_status' => 'approved'])->saveQuietly();
    expect($this->service->preview($rows)['errors'])->not->toBeEmpty();
});

it('validates visit and DCR relationships', function () {
    $dcr = SalesDcr::create(['user_id' => auth()->id(), 'dcr_date' => '2026-01-15', 'territory_id' => $this->territory->id]);
    expect(fn () => app(TravelSegmentValidation::class)->validate(['sales_dcr_id' => $dcr->id, 'visit_id' => 999999]))->toThrow(ValidationException::class);
});

it('renders the import page and limits all travel relationship dropdowns', function () {
    useFieldTravelImportTenant();
    Livewire::test(ImportPage::class, ['embedded' => true])->assertSee('Download Excel template')->assertSee('Instructions and examples');
    $component = Livewire::test(CreateTravelSegment::class)->assertSuccessful();
    foreach (['sales_dcr_id', 'visit_id', 'sales_tour_plan_detail_id', 'patch_id', 'from_account_id', 'to_account_id', 'from_area_town_id', 'to_area_town_id', 'transport_mode_id'] as $field) {
        $component->assertFormFieldExists($field, fn ($select) => $select->isSearchable() && ! $select->isPreloaded() && $select->getOptionsLimit() === 50);
    }
});

it('writes both templates with instructions and excludes examples from reading', function () {
    $path = tempnam(sys_get_temp_dir(), 'field-template-');
    try {
        app(FieldTravelTemplate::class)->write($path);
        expect(fn () => $this->service->read($path))->toThrow(ValidationException::class, 'No data');
        app(FieldTravelTemplate::class)->write($path, true);
        expect(fn () => $this->service->read($path, true))->toThrow(ValidationException::class, 'No data');
    } finally {
        unlink($path);
    }
});

it('imports assignments for existing patches without a Patches sheet', function () {
    $rows = fieldTravelRows();
    unset($rows['Standard Fares']);
    $preview = $this->service->preview($rows);
    $this->service->import($rows, $preview['fingerprint']);
    $type = TypeMaster::create(['name' => 'Customer', 'typeable_type' => AccountMaster::class]);
    $customer = AccountMaster::create(['name' => 'Existing customer', 'owner_id' => auth()->id(), 'type_master_id' => $type->id]);
    Address::create(['addressable_id' => $customer->id, 'addressable_type' => AccountMaster::class, 'street' => 'Main road', 'pin_code' => $this->from->pin_code]);
    $rows = ['Patch Customers' => [['row' => 2, 'data' => ['patch_code' => 'PATCH-01', 'customer_code' => $customer->account_code, 'sequence_no' => '1', 'distance_to_next_km' => '5']]]];
    $preview = $this->service->preview($rows);
    $this->assertSame([], $preview['errors']);
    expect($this->service->import($rows, $preview['fingerprint'])['Create'])->toBe(1);
});

it('rejects updates to approved patch records in preview', function () {
    $rows = fieldTravelRows();
    $preview = $this->service->preview($rows);
    $this->service->import($rows, $preview['fingerprint']);
    Patch::first()->forceFill(['approval_status' => 'approved'])->saveQuietly();
    $rows['Patches'][0]['data']['patch_name'] = 'Changed approved patch';
    expect($this->service->preview($rows)['errors'])->not->toBeEmpty();
});

it('executes a saved import job once and restores the authenticated user', function () {
    Storage::fake('local');
    $rows = fieldTravelRows();
    $preview = $this->service->preview($rows);
    $token = (string) Str::uuid();
    $user = auth()->user();
    $path = 'field-travel-imports/'.$user->id.'/'.$token.'.json';
    Storage::disk('local')->put($path, json_encode(['status' => 'queued', 'rows' => $rows, 'fingerprint' => $preview['fingerprint']]));
    $job = new ProcessFieldTravelImport($token, $user->id, null);
    $job->handle($this->service);
    $job->handle($this->service);
    expect(Patch::count())->toBe(1);
    expect(auth()->id())->toBe($user->id);
    $payload = json_decode(Storage::disk('local')->get($path), true);
    expect($payload['status'])->toBe('complete');
    expect($payload)->not->toHaveKey('rows');
});

it('refuses a background job in the wrong tenant context', function () {
    $job = new ProcessFieldTravelImport((string) Str::uuid(), auth()->id(), 99999);
    expect(fn () => $job->handle($this->service))->toThrow(RuntimeException::class, 'company context');
    expect(Patch::count())->toBe(0);
});

it('uploads previews and imports through the livewire page', function () {
    useFieldTravelImportTenant();
    Storage::fake('local');
    $path = tempnam(sys_get_temp_dir(), 'travel-ui-');
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->getCurrentSheet()->setName('Patches');
    $writer->addRow(Row::fromValues(FieldTravelTemplate::COLUMNS['Patches']));
    $writer->addRow(Row::fromValues(array_values(fieldTravelRows()['Patches'][0]['data'])));
    $writer->close();
    try {
        $upload = UploadedFile::fake()->createWithContent('patches.xlsx', file_get_contents($path));
        $page = Livewire::test(ImportPage::class, ['embedded' => true])->set('file', $upload)->call('previewFile')->assertHasNoErrors()->assertSet('status', 'preview')->assertSee('PATCH-01');
        expect(Patch::count())->toBe(0);
        $page->call('importReviewed')->assertHasNoErrors()->assertSet('status', 'complete')->assertSee('1 created');
        expect(Patch::count())->toBe(1);
    } finally {
        unlink($path);
    }
});
