<?php

use App\Filament\Pages\Onboarding;
use App\Jobs\ProcessAccountImport;
use App\Models\AccountMaster;
use App\Models\Address;
use App\Models\City;
use App\Models\CityPinCode;
use App\Models\Country;
use App\Models\IndustryType;
use App\Models\Organization;
use App\Models\Patch;
use App\Models\RatingType;
use App\Models\State;
use App\Models\Tenant;
use App\Models\Territory;
use App\Models\TypeMaster;
use App\Services\AccountOnboardingService;
use App\Services\CompanyOnboardingService;
use App\Services\DataImport\AccountImportTemplate;
use Database\Seeders\AccountReportingMasterSeeder;
use Database\Seeders\AccountStatusSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['cache.stores.file' => ['driver' => 'array']]);
    Storage::fake('local');
    $this->actingAs(reportingUser('Account Setup Owner'));
    auth()->user()->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->organization = Organization::create(['name' => 'Account Import Company', 'metadata' => ['company_setup' => ['organization_saved' => true, 'employees_reviewed' => true]]]);
    app()->instance(CompanyOnboardingService::class, new class extends CompanyOnboardingService
    {
        public function authorize(): void {}
    });
    $this->seed([AccountStatusSeeder::class, AccountReportingMasterSeeder::class]);
    $this->type = TypeMaster::create(['name' => 'Import Doctor', 'typeable_type' => AccountMaster::class]);
    $this->rating = RatingType::create(['name' => 'Import A']);
    $this->service = app(AccountOnboardingService::class);
});

function accountImportRows(array $overrides = []): array
{
    return [['line' => 2, 'data' => array_replace(['external_reference' => 'ERP:0001', 'name' => 'Import Doctor One', 'account_type' => 'Import Doctor', 'owner_email' => auth()->user()->email], $overrides)]];
}

it('previews without writes and imports relationships in any order with safe reruns', function () {
    $rows = accountImportRows(['parent_reference' => 'ERP:0002', 'institution_reference' => 'ERP:0002', 'supplier_reference' => 'ERP:0002', 'phone_number' => '9000000011']);
    $rows[] = ['line' => 3, 'data' => array_replace($rows[0]['data'], ['external_reference' => 'ERP:0002', 'name' => 'Import Institution', 'parent_reference' => '', 'institution_reference' => '', 'supplier_reference' => ''])];
    $preview = $this->service->preview($rows);
    expect($preview['errors'])->toBe([])->and($preview['created'])->toBe(2);
    $this->assertDatabaseCount('account_masters', 0);
    expect($this->service->import($preview['rows']))->toBe(['created' => 2, 'updated' => 0]);
    $account = AccountMaster::where('external_reference', 'ERP:0001')->sole();
    expect($account->parent_id)->toBe(AccountMaster::where('external_reference', 'ERP:0002')->sole()->id)
        ->and($account->institution_account_id)->toBe($account->parent_id)->and($account->supplier_account_id)->toBe($account->parent_id)
        ->and($account->accountStatus->code)->toBe('prospect');
    $code = $account->account_code;
    $rows[0]['data']['phone_number'] = '';
    $rows[0]['data']['name'] = 'Updated Doctor';
    expect($this->service->import($rows))->toBe(['created' => 0, 'updated' => 2]);
    expect($account->fresh()->phone_number)->toBe('9000000011')->and($account->fresh()->account_code)->toBe($code);
    $this->assertDatabaseCount('account_masters', 2);
    expect($this->organization->fresh()->metadata['company_setup']['accounts_reviewed'])->toBeTrue();
});

it('imports custom account codes and keeps automatic generation available', function () {
    $this->service->import(accountImportRows(['account_code' => 'MY-0023']));
    $account = AccountMaster::where('external_reference', 'ERP:0001')->sole();
    expect($account->account_code)->toBe('MY-0023');
    $this->service->import(accountImportRows());
    expect($account->fresh()->account_code)->toBe('MY-0023');
    $nextCode = 'ACC-'.str_pad((string) ($account->id + 1), 8, '0', STR_PAD_LEFT);
    $this->service->import(accountImportRows(['account_code' => $nextCode]));
    $this->service->import(accountImportRows(['external_reference' => 'ERP:0002']));
    $generated = AccountMaster::where('external_reference', 'ERP:0002')->sole();
    expect($generated->account_code)->toStartWith('ACC-')->not->toBe($nextCode);
    expect($this->service->preview(accountImportRows(['external_reference' => 'ERP:0003', 'account_code' => $nextCode]))['errors'])->not->toBeEmpty();
    $rows = accountImportRows(['external_reference' => 'ERP:0003', 'account_code' => 'DUPLICATE']);
    $rows[] = ['line' => 3, 'data' => array_replace($rows[0]['data'], ['external_reference' => 'ERP:0004'])];
    expect($this->service->preview($rows)['errors'][0]['message'])->toContain('Duplicate account_code');
});

it('rejects invalid mappings and duplicate references without partial imports', function (array $override) {
    $rows = accountImportRows();
    $rows[] = ['line' => 8, 'data' => array_replace($rows[0]['data'], ['external_reference' => 'ERP:0002'], $override)];
    expect($this->service->preview($rows)['errors'])->not->toBeEmpty();
    expect(fn () => $this->service->import($rows))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('account_masters', 0);
})->with([
    'missing owner' => [['owner_email' => 'missing@example.test']],
    'missing type' => [['account_type' => 'Missing']],
    'duplicate reference' => [['external_reference' => 'erp:0001']],
    'missing institution' => [['institution_reference' => 'MISSING']],
    'self reference' => [['parent_reference' => 'ERP:0002']],
    'invalid percentage' => [['commission' => '101']],
    'partial potential' => [['potential_value' => '10']],
    'partial address' => [['street' => 'Clinic Road']],
    'invalid monthly target' => [['calls_per_month' => '4', 'target_from' => '2026-01-15']],
]);

it('rejects circular account hierarchies and stale previews', function () {
    $rows = accountImportRows(['parent_reference' => 'ERP:0002']);
    $rows[] = ['line' => 3, 'data' => array_replace($rows[0]['data'], ['external_reference' => 'ERP:0002', 'parent_reference' => 'ERP:0001'])];
    expect($this->service->preview($rows)['errors'][0]['message'])->toContain('Circular');
    $preview = $this->service->preview(accountImportRows());
    $this->type->delete();
    expect(fn () => $this->service->import($preview['rows']))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('account_masters', 0);
});

it('imports addresses patches profiles and targets before activating and preserves periods on reruns', function () {
    $this->type->update(['activation_requirements' => ['primary_address', 'patch', 'specialty']]);
    expect($this->service->preview(accountImportRows(['status_code' => 'active']))['errors'])->not->toBeEmpty();
    $territory = Territory::create(['name' => 'Import West', 'code' => 'IMP-WEST']);
    $country = Country::create(['name' => 'Import Country']);
    $state = State::create(['name' => 'Import State', 'country_id' => $country->id]);
    $city = City::create(['name' => 'Import City', 'state_id' => $state->id, 'country_id' => $country->id]);
    CityPinCode::create(['city_id' => $city->id, 'area_town' => 'Import Area', 'pin_code' => 400001]);
    TypeMaster::create(['name' => 'Clinic', 'typeable_type' => Address::class]);
    $rows = accountImportRows(['status_code' => 'active', 'territory_code' => 'IMP-WEST', 'patch_codes' => 'IMP-PATCH', 'specialty_code' => 'specialty_cardiology', 'address_type' => 'Clinic', 'street' => 'Clinic Road', 'city' => 'Import City', 'area' => 'Import Area', 'pin_code' => '400001', 'segment' => 'Import A', 'profile_from' => '2026-01-01', 'profile_to' => '2026-12-31', 'potential_value' => '12000', 'potential_unit_code' => 'potential_unit_revenue', 'potential_period' => 'monthly', 'currency' => 'INR', 'assessed_on' => '2026-01-01', 'calls_per_month' => '4', 'target_from' => '2026-01-01', 'target_to' => '2026-12-31']);
    expect($this->service->preview($rows)['errors'])->toBe([]);
    $this->assertDatabaseCount('patches', 0);
    $this->service->import($rows);
    $patch = Patch::where('code', 'IMP-PATCH')->sole();
    expect($patch->name)->toBe('IMP-PATCH')->and($patch->territory_id)->toBe($territory->id)
        ->and($patch->city_pin_code_id)->toBe(CityPinCode::where('pin_code', 400001)->sole()->id);
    $account = AccountMaster::where('external_reference', 'ERP:0001')->sole();
    expect($account->accountStatus->code)->toBe('active')->and($account->patches->modelKeys())->toBe([$patch->id]);
    $rows[0]['data']['profile_to'] = '';
    $rows[0]['data']['target_to'] = '';
    $this->service->import($rows);
    $this->assertDatabaseCount('patches', 1);
    expect($account->addresses()->count())->toBe(1)->and($account->divisionProfiles()->count())->toBe(1)->and($account->callTargets()->count())->toBe(1)
        ->and($account->callTargets()->first()->effective_to->toDateString())->toBe('2026-12-31');
    $rows[0]['data']['target_from'] = '2026-02-01';
    expect($this->service->preview($rows)['errors'][0]['message'])->toContain('overlaps');
});

it('rolls back the whole file when a model rejects a write after preview', function () {
    Territory::create(['name' => 'Rollback territory', 'code' => 'ROLLBACK']);
    $rows = accountImportRows(['territory_code' => 'ROLLBACK', 'patch_codes' => 'ROLLBACK-PATCH']);
    $rows[] = ['line' => 3, 'data' => array_replace($rows[0]['data'], ['external_reference' => 'ERP:0002'])];
    // A one-time account listener simulates a save-time business rule on the second row.
    $dispatcher = AccountMaster::getEventDispatcher();
    AccountMaster::setEventDispatcher(clone $dispatcher);
    AccountMaster::creating(function ($account) {
        if ($account->external_reference === 'ERP:0002') {
            throw ValidationException::withMessages(['name' => 'Rejected account']);
        }
    });
    try {
        expect(fn () => $this->service->import($rows))->toThrow(ValidationException::class, 'Row 3');
        $this->assertDatabaseCount('account_masters', 0);
        $this->assertDatabaseCount('patches', 0);
        $this->assertDatabaseCount('account_assignment_histories', 0);
    } finally {
        AccountMaster::setEventDispatcher($dispatcher);
    }
});

it('creates a missing patch once for multiple accounts and preserves it on reruns', function () {
    $territory = Territory::create(['name' => 'Shared territory', 'code' => 'SHARED']);
    $rows = accountImportRows(['territory_code' => 'SHARED', 'patch_codes' => 'PATCH-0023; PATCH-0023;']);
    $rows[] = ['line' => 3, 'data' => array_replace($rows[0]['data'], ['external_reference' => 'ERP:0002'])];
    expect($this->service->preview($rows)['errors'])->toBe([]);
    $this->assertDatabaseCount('patches', 0);
    $this->service->import($rows);
    $patch = Patch::sole();
    expect($patch->territory_id)->toBe($territory->id)->and($patch->companies()->count())->toBe(2);
    $patch->update(['name' => 'Renamed patch']);
    $this->service->import($rows);
    expect(Patch::sole()->name)->toBe('Renamed patch');
});

it('rejects missing patch territories deleted patches and conflicting new patch territories', function () {
    expect($this->service->preview(accountImportRows(['patch_codes' => 'MISSING']))['errors'][0]['message'])->toContain('territory_code');
    $first = Territory::create(['name' => 'First territory', 'code' => 'FIRST']);
    Territory::create(['name' => 'Second territory', 'code' => 'SECOND']);
    $patch = Patch::create(['name' => 'Deleted patch', 'code' => 'DELETED', 'territory_id' => $first->id]);
    $patch->delete();
    expect($this->service->preview(accountImportRows(['patch_codes' => 'DELETED']))['errors'][0]['message'])->toContain('deleted');
    $rows = accountImportRows(['territory_code' => 'FIRST', 'patch_codes' => 'NEW']);
    $rows[] = ['line' => 3, 'data' => array_replace($rows[0]['data'], ['external_reference' => 'ERP:0002', 'territory_code' => 'SECOND'])];
    expect($this->service->preview($rows)['errors'][0]['message'])->toContain('different territories');
    expect(fn () => $this->service->import($rows))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('account_masters', 0);
});

it('creates a blank Excel template with tenant references and reads only the Accounts sheet', function () {
    $path = tempnam(sys_get_temp_dir(), 'accounts-test-');
    try {
        app(AccountImportTemplate::class)->write($path);
        $reader = new Reader;
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $sheets[] = $sheet->getName();
        }
        $reader->close();
        expect($sheets)->toBe(['Accounts', 'Instructions', 'References', 'Field Guide'])->and($this->service->readFile($path, true))->toBe([]);
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Accounts');
        $writer->addRow(Row::fromValues(array_keys(accountImportRows()[0]['data'])));
        $writer->addRow(Row::fromValues(array_values(accountImportRows()[0]['data'])));
        $writer->addNewSheetAndMakeItCurrent()->setName('Instructions');
        $writer->addRow(Row::fromValues(['Not an account']));
        $writer->close();
        expect($this->service->preview($this->service->readFile($path, true))['created'])->toBe(1);
    } finally {
        unlink($path);
    }
});

it('requires onboarding authorization for reading previewing importing and downloading templates', function () {
    app()->forgetInstance(CompanyOnboardingService::class);
    expect(fn () => $this->service->preview(accountImportRows()))->toThrow(HttpException::class)
        ->and(fn () => $this->service->import(accountImportRows()))->toThrow(HttpException::class)
        ->and(fn () => $this->service->readFile('missing.csv'))->toThrow(HttpException::class);
});

it('supports preview and confirmation in company setup and invalidates changed uploads', function () {
    Queue::fake();
    config(['multitenancy.switch_tenant_tasks' => []]);
    app('db')->connection('tenant')->setPdo(app('db')->connection()->getPdo())->setReadPdo(app('db')->connection()->getPdo());
    $tenant = new Tenant;
    $tenant->forceFill(['id' => 99999, 'name' => 'Test', 'master_setup_status' => 'ready']);
    $tenant->makeCurrent();
    try {
        auth()->user()->assignRole(Role::findOrCreate('super_admin', 'tenant'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $csv = 'external_reference,name,account_type,owner_email'."\n".'ERP:0001,Import Doctor One,Import Doctor,'.auth()->user()->email."\n";
        $page = Livewire::test(Onboarding::class)->assertSet('step', 4)->assertSee('Import account masters')
            ->set('accountFile', UploadedFile::fake()->createWithContent('accounts.csv', $csv))
            ->call('previewAccounts')->assertHasNoErrors()->assertSee('Review before importing');
        $this->assertDatabaseCount('account_masters', 0);
        $page->call('importAccounts')->assertHasNoErrors()->assertSet('accountPreview', [])->assertSee('Account import queued');
        $page->call('importAccounts')->assertHasNoErrors();
        Queue::assertPushed(ProcessAccountImport::class, 1);
        $this->assertDatabaseCount('account_masters', 0);
        Livewire::test(Onboarding::class)->assertSee('Account import queued');
        $job = Queue::pushed(ProcessAccountImport::class)->first();
        expect($job->connection)->toBe('database');
        $job->handle($this->service);
        $page->call('refreshAccountImport')->assertSee('1 accounts created');
        Livewire::test(Onboarding::class)->assertSee('1 accounts created');
        $this->assertDatabaseCount('account_masters', 1);
        $page->set('accountFile', UploadedFile::fake()->createWithContent('accounts.csv', str_replace('Import Doctor,', 'Missing Type,', $csv)))
            ->call('previewAccounts')->assertSee('Nothing has been imported')->call('downloadAccountErrors')->assertFileDownloaded('account-import-errors.csv');
        $page->set('accountFile', UploadedFile::fake()->createWithContent('accounts.csv', $csv))->assertSet('accountPreview', []);
    } finally {
        Tenant::forgetCurrent();
    }
});

it('resolves numeric source references and detects cycles through accounts without references', function () {
    $rows = accountImportRows(['external_reference' => '123', 'parent_reference' => '456']);
    $rows[] = ['line' => 3, 'data' => array_replace($rows[0]['data'], ['external_reference' => '456', 'parent_reference' => ''])];
    expect($this->service->preview($rows)['errors'])->toBe([]);
    $this->service->import($rows);
    $first = AccountMaster::where('external_reference', '123')->sole();
    $second = AccountMaster::where('external_reference', '456')->sole();
    $manual = AccountMaster::create(['name' => 'Manual Middle', 'type_master_id' => $this->type->id, 'owner_id' => auth()->id(), 'parent_id' => $first->id]);
    $second->update(['parent_id' => $manual->id]);
    expect($this->service->preview($rows)['errors'][0]['message'])->toContain('Circular');
});

it('preserves null lifecycle status and rejects reuse of deleted external references', function () {
    $this->service->import(accountImportRows());
    $account = AccountMaster::where('external_reference', 'ERP:0001')->sole();
    $account->update(['account_status_id' => null]);
    $this->service->import(accountImportRows());
    expect($account->fresh()->account_status_id)->toBeNull();
    $account->delete();
    expect($this->service->preview(accountImportRows())['errors'][0]['message'])->toContain('deleted account');
});

it('rejects malformed headers and preserves actual source line numbers', function () {
    $path = tempnam(sys_get_temp_dir(), 'account-csv-');
    try {
        file_put_contents($path, "external_reference,name,account_type,owner_email\n\nERP:0001,Example,Unknown,".auth()->user()->email."\n");
        $preview = $this->service->preview($this->service->readFile($path));
        expect($preview['errors'][0]['row'])->toBe(3);
        file_put_contents($path, "external_reference,name,name,owner_email\n");
        expect(fn () => $this->service->readFile($path))->toThrow(ValidationException::class, 'template headers');
    } finally {
        unlink($path);
    }
});

it('imports expanded account and related details and safely repeats them', function () {
    $subtype = TypeMaster::create(['name' => 'Specialist', 'typeable_type' => AccountMaster::class, 'parent_id' => $this->type->id]);
    $industry = IndustryType::create(['name' => 'Healthcare']);
    $rows = accountImportRows([
        'account_subtype' => 'Specialist', 'industry' => 'Healthcare', 'alias' => 'Clinic alias', 'secondary_email' => 'secondary@example.test',
        'website' => 'https://example.test', 'description' => 'Imported business details', 'no_of_employees' => '12', 'annual_revenue' => '150000', 'sic_code' => '8011',
        'contact_first_name' => 'Asha', 'contact_last_name' => 'Shah', 'contact_email' => 'asha@example.test', 'contact_mobile_number' => '9000000012',
        'bank_name' => 'Example Bank', 'bank_account_number' => '000123456', 'bank_account_ifsc_code' => 'EXAM0000001', 'bank_account_iban' => 'EXAMPLE123',
        'credit_type' => 'Both', 'credit_days' => '30', 'credit_limit' => '50000',
        'gst_number' => 'EXAMPLE-GST-001', 'gst_state_name' => 'Gujarat', 'gst_state_code' => '24', 'gst_type' => 'Regular', 'pan_number' => 'EXAMPLEPAN',
        'tan_number' => 'EXAMPLETAN', 'tds_rate' => '2', 'is_tds_deduct' => 'yes',
    ]);
    expect($this->service->preview($rows)['errors'])->toBe([]);
    $this->service->import($rows);
    $this->service->import($rows);
    $account = AccountMaster::where('external_reference', 'ERP:0001')->sole();
    expect($account->type_master_id)->toBe($subtype->id)->and($account->industry_type_id)->toBe($industry->id)
        ->and($account->secondary_email)->toBe('secondary@example.test')->and($account->alias)->toBe('Clinic alias')
        ->and($account->contactDetails()->count())->toBe(1)->and($account->bankDetail()->count())->toBe(1)
        ->and($account->bankDetail()->first()->bank_account_number)->toBe('000123456')
        ->and($account->creditDetail->credit_days)->toBe(30)->and($account->gstDetail->pan_number)->toBe('EXAMPLEPAN')
        ->and($account->statutoryDetail->tan_number)->toBe('EXAMPLETAN');
    $this->service->import(accountImportRows());
    expect($account->fresh()->creditDetail->credit_limit)->toBe('50000.00');
});

it('rejects partial and conflicting optional details during preview', function (array $fields) {
    $preview = $this->service->preview(accountImportRows($fields));
    expect($preview['errors'])->not->toBeEmpty();
    expect(fn () => $this->service->import($preview['rows']))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('account_masters', 0);
})->with([
    [['bank_name' => 'Missing number']],
    [['gst_number' => 'Missing required GST details']],
    [['credit_limit' => '1000']],
    [['contact_first_name' => 'Missing identity']],
    [['account_subtype' => 'Unknown subtype']],
    [['website' => 'not-a-url']],
]);

it('rejects bank numbers repeated across source accounts before writing', function () {
    $rows = accountImportRows(['bank_name' => 'Bank', 'bank_account_number' => '000123']);
    $rows[] = ['line' => 3, 'data' => array_replace($rows[0]['data'], ['external_reference' => 'ERP:0002'])];
    expect($this->service->preview($rows)['errors'][0]['message'])->toContain('Duplicate bank_account_number');
    $this->assertDatabaseCount('account_masters', 0);
});

function accountImportAddress(): array
{
    $country = Country::create(['name' => 'Import Country']);
    $state = State::create(['name' => 'Import State', 'country_id' => $country->id]);
    City::create(['name' => 'Import City', 'state_id' => $state->id, 'country_id' => $country->id]);
    TypeMaster::create(['name' => 'Office Address', 'typeable_type' => Address::class]);

    return ['address_type' => 'Office Address', 'street' => '76, Business Road', 'city' => 'Import City', 'area' => 'Uploaded area', 'pin_code' => '411005'];
}

it('matches account addresses by city and PIN ignoring area spelling and duplicate PINs', function () {
    $fields = accountImportAddress();
    $city = City::where('name', 'Import City')->sole();
    $area = CityPinCode::create(['city_id' => $city->id, 'area_town' => 'Existing area', 'pin_code' => 411005]);
    CityPinCode::create(['city_id' => $city->id, 'area_town' => 'Another area', 'pin_code' => 411005]);
    $preview = $this->service->preview(accountImportRows($fields));
    expect($preview['errors'])->toBe([])->and($preview['plans'][0]['address']['area_town_id'])->toBe($area->id);
    $this->service->import($preview['rows']);
    $this->service->import($preview['rows']);
    expect(AccountMaster::sole()->addresses()->sole()->area_town_id)->toBe($area->id);
    expect($area->fresh()->area_town)->toBe('Existing area');
    $this->assertDatabaseCount('city_pin_codes', 2);
});

it('creates one missing account area only on confirmation and reuses it across rows and retries', function () {
    $fields = accountImportAddress();
    $rows = accountImportRows($fields);
    $rows[] = ['line' => 3, 'data' => array_replace($rows[0]['data'], ['external_reference' => 'ERP:0002', 'area' => 'Different spelling'])];
    $preview = $this->service->preview($rows);
    expect($preview['errors'])->toBe([]);
    $this->assertDatabaseCount('city_pin_codes', 0);
    $this->service->import($preview['rows']);
    $this->service->import($preview['rows']);
    $area = CityPinCode::sole();
    $city = City::where('name', 'Import City')->sole();
    expect($area->area_town)->toBe('Uploaded area')->and($area->state_id)->toBe($city->state_id)->and($area->country_id)->toBe($city->country_id);
    foreach (AccountMaster::all() as $account) {
        expect($account->addresses()->sole()->area_town_id)->toBe($area->id);
    }
});

it('does not reuse the same PIN from another city for an account address', function () {
    $fields = accountImportAddress();
    $city = City::where('name', 'Import City')->sole();
    $otherCity = City::create(['name' => 'Other City', 'state_id' => $city->state_id, 'country_id' => $city->country_id]);
    CityPinCode::create(['city_id' => $otherCity->id, 'area_town' => 'Other area', 'pin_code' => 411005]);
    $this->service->import(accountImportRows($fields));
    $address = AccountMaster::sole()->addresses()->sole();
    expect($address->city_id)->toBe($city->id)->and(CityPinCode::findOrFail($address->area_town_id)->city_id)->toBe($city->id);
    $this->assertDatabaseCount('city_pin_codes', 2);
});

it('rejects missing cities and invalid PINs without creating account areas', function (array $override) {
    $fields = array_replace(accountImportAddress(), $override);
    expect($this->service->preview(accountImportRows($fields))['errors'])->not->toBeEmpty();
    expect(fn () => $this->service->import(accountImportRows($fields)))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('city_pin_codes', 0);
})->with([[['city' => 'Missing City']], [['pin_code' => '411005abc']], [['pin_code' => '99999999999']]]);

it('rolls back a newly created account area if a later account write fails', function () {
    $rows = accountImportRows(accountImportAddress());
    $rows[] = ['line' => 3, 'data' => array_replace($rows[0]['data'], ['external_reference' => 'ERP:0002'])];
    $dispatcher = AccountMaster::getEventDispatcher();
    AccountMaster::setEventDispatcher(clone $dispatcher);
    AccountMaster::creating(function ($account): void {
        if ($account->external_reference === 'ERP:0002') {
            throw ValidationException::withMessages(['name' => 'Rejected account']);
        }
    });
    try {
        expect(fn () => $this->service->import($rows))->toThrow(ValidationException::class);
        $this->assertDatabaseCount('city_pin_codes', 0);
        $this->assertDatabaseCount('account_masters', 0);
    } finally {
        AccountMaster::setEventDispatcher($dispatcher);
    }
});

it('keeps a full workbook preview on the server and downloads every error without rerendering', function () {
    Queue::fake();
    config(['multitenancy.switch_tenant_tasks' => []]);
    app('db')->connection('tenant')->setPdo(app('db')->connection()->getPdo())->setReadPdo(app('db')->connection()->getPdo());
    $tenant = new Tenant;
    $tenant->forceFill(['id' => 99999, 'name' => 'Test', 'master_setup_status' => 'ready']);
    $tenant->makeCurrent();
    try {
        auth()->user()->assignRole(Role::findOrCreate('super_admin', 'tenant'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $rows = $plans = $errors = [];
        for ($index = 1; $index <= 500; $index++) {
            $data = array_fill_keys(AccountOnboardingService::COLUMNS, '');
            $data = array_replace($data, accountImportRows()[0]['data'], ['external_reference' => 'SAMPLE:ACC'.str_pad((string) $index, 4, '0', STR_PAD_LEFT)]);
            $rows[] = ['line' => $index + 1, 'data' => $data];
            if ($index % 2 === 0) {
                $plans[] = ['line' => $index + 1, 'id' => null, 'data' => $data];
            } else {
                $errors[] = ['row' => $index + 1, 'reference' => $data['external_reference'], 'message' => 'City was not found.'];
            }
        }
        $service = Mockery::mock(AccountOnboardingService::class)->makePartial();
        $service->shouldReceive('readFile')->twice()->andReturn($rows);
        $service->shouldReceive('preview')->twice()->with($rows)->andReturn(
            ['rows' => $rows, 'plans' => $plans, 'errors' => $errors, 'created' => 250, 'updated' => 0],
            ['rows' => $rows, 'plans' => $plans, 'errors' => [], 'created' => 500, 'updated' => 0],
        );
        $service->shouldReceive('import')->once()->with($rows, Mockery::type('callable'))->andReturn(['created' => 500, 'updated' => 0]);
        app()->instance(AccountOnboardingService::class, $service);
        $page = Livewire::test(Onboarding::class)
            ->set('accountFile', UploadedFile::fake()->createWithContent('accounts.csv', 'test'))
            ->call('previewAccounts')->assertHasNoErrors()->assertSee('Confirm import')->assertSee('Working… Previewing file')
            ->assertSet('accountPreview.row_count', 500)->assertSet('accountPreview.error_count', 250);
        expect($page->get('accountPreview')['plans'])->toHaveCount(25);
        expect($page->get('accountPreview')['errors'])->toHaveCount(25);
        expect($page->get('accountPreview'))->not->toHaveKey('rows');
        expect(strlen(json_encode($page->get('accountPreview'))))->toBeLessThan(150000);
        $page->call('showAccountPreviewPage', 10)->assertSet('accountPreviewPage', 10)->assertSee('SAMPLE:ACC0500');
        $page->call('downloadAccountErrors')->assertFileDownloaded('account-import-errors.csv');
        $csv = base64_decode($page->effects['download']['content']);
        expect($csv)->toContain('SAMPLE:ACC0499')->and(substr_count(trim($csv), "\n"))->toBe(250);
        expect($page->effects)->not->toHaveKey('html');
        $page->call('importAccounts')->assertStatus(422);
        $page = Livewire::test(Onboarding::class)
            ->set('accountFile', UploadedFile::fake()->createWithContent('accounts.csv', 'test'))
            ->call('previewAccounts')->assertHasNoErrors()
            ->call('showAccountPreviewPage', 10)
            ->call('importAccounts')->assertHasNoErrors()->assertSee('Account import queued')
            ->assertSet('accountPreviewToken', null)->assertSet('accountPreview', []);
        $job = Queue::pushed(ProcessAccountImport::class)->first();
        $job->handle($service);
        $page->call('refreshAccountImport')->assertSee('500 accounts created');
        $page->call('downloadAccountErrors')->assertHasErrors('accountFile');
    } finally {
        Tenant::forgetCurrent();
    }
});

it('reports job progress and restores authentication after importing accounts', function () {
    $job = new ProcessAccountImport((string) Str::uuid(), (int) auth()->id(), null);
    $key = ProcessAccountImport::statusKey((int) auth()->id(), null);
    Cache::store('file')->put($key, ['token' => $job->token, 'status' => 'queued', 'processed' => 0, 'total' => 1]);
    Storage::disk('local')->put($job->payloadPath(), json_encode(accountImportRows()));
    $previous = auth()->user();
    $states = [];
    $importer = Mockery::mock(AccountOnboardingService::class)->makePartial();
    $importer->shouldReceive('import')->once()->andReturnUsing(function ($rows, $progress) use (&$states, $key) {
        $states[] = Cache::store('file')->get($key);
        $progress('Importing accounts', 1, 1);
        $states[] = Cache::store('file')->get($key);

        return $this->service->import($rows);
    });
    $job->handle($importer);
    expect($states[0]['status'])->toBe('running')->and($states[1]['processed'])->toBe(1);
    expect(Cache::store('file')->get($key)['status'])->toBe('complete');
    expect(auth()->user())->toBe($previous);
    Storage::disk('local')->assertMissing($job->payloadPath());
    $job->handle($importer);
    $this->assertDatabaseCount('account_masters', 1);
});

it('reports failed account jobs and preserves completed status on a late failure callback', function () {
    $job = new ProcessAccountImport((string) Str::uuid(), (int) auth()->id(), null);
    $key = ProcessAccountImport::statusKey((int) auth()->id(), null);
    Cache::store('file')->put($key, ['token' => $job->token, 'status' => 'queued']);
    Storage::disk('local')->put($job->payloadPath(), json_encode(accountImportRows(['account_type' => 'Missing'])));
    $previous = auth()->user();
    expect(fn () => $job->handle($this->service))->toThrow(ValidationException::class);
    expect(Cache::store('file')->get($key)['status'])->toBe('failed');
    expect(Cache::store('file')->get($key)['message'])->toContain('Missing');
    expect(auth()->user())->toBe($previous);
    $this->assertDatabaseCount('account_masters', 0);
    Cache::store('file')->put($key, ['token' => $job->token, 'status' => 'running']);
    $job->failed(new RuntimeException('timeout'));
    expect(Cache::store('file')->get($key)['status'])->toBe('failed');
    Cache::store('file')->put($key, ['token' => $job->token, 'status' => 'complete']);
    $job->failed(new RuntimeException('late callback'));
    expect(Cache::store('file')->get($key)['status'])->toBe('complete');
});

it('rejects an account job in the wrong company context', function () {
    $job = new ProcessAccountImport((string) Str::uuid(), (int) auth()->id(), 123);
    expect(fn () => $job->handle($this->service))->toThrow(RuntimeException::class, 'company context');
    $this->assertDatabaseCount('account_masters', 0);
});
