<?php

use App\Filament\Pages\Onboarding;
use App\Models\AccountMaster;
use App\Models\Address;
use App\Models\City;
use App\Models\CityPinCode;
use App\Models\Country;
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
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
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
    $patch = Patch::withoutEvents(fn () => Patch::create(['name' => 'Import Patch', 'code' => 'IMP-PATCH', 'territory_id' => $territory->id]));
    $country = Country::create(['name' => 'Import Country']);
    $state = State::create(['name' => 'Import State', 'country_id' => $country->id]);
    $city = City::create(['name' => 'Import City', 'state_id' => $state->id, 'country_id' => $country->id]);
    CityPinCode::create(['city_id' => $city->id, 'area_town' => 'Import Area', 'pin_code' => 400001]);
    TypeMaster::create(['name' => 'Clinic', 'typeable_type' => Address::class]);
    $rows = accountImportRows(['status_code' => 'active', 'territory_code' => 'IMP-WEST', 'patch_codes' => 'IMP-PATCH', 'specialty_code' => 'specialty_cardiology', 'address_type' => 'Clinic', 'street' => 'Clinic Road', 'city' => 'Import City', 'area' => 'Import Area', 'pin_code' => '400001', 'segment' => 'Import A', 'profile_from' => '2026-01-01', 'profile_to' => '2026-12-31', 'potential_value' => '12000', 'potential_unit_code' => 'potential_unit_revenue', 'potential_period' => 'monthly', 'currency' => 'INR', 'assessed_on' => '2026-01-01', 'calls_per_month' => '4', 'target_from' => '2026-01-01', 'target_to' => '2026-12-31']);
    expect($this->service->preview($rows)['errors'])->toBe([]);
    $this->service->import($rows);
    $account = AccountMaster::where('external_reference', 'ERP:0001')->sole();
    expect($account->accountStatus->code)->toBe('active')->and($account->patches->modelKeys())->toBe([$patch->id]);
    $rows[0]['data']['profile_to'] = '';
    $rows[0]['data']['target_to'] = '';
    $this->service->import($rows);
    expect($account->addresses()->count())->toBe(1)->and($account->divisionProfiles()->count())->toBe(1)->and($account->callTargets()->count())->toBe(1)
        ->and($account->callTargets()->first()->effective_to->toDateString())->toBe('2026-12-31');
    $rows[0]['data']['target_from'] = '2026-02-01';
    expect($this->service->preview($rows)['errors'][0]['message'])->toContain('overlaps');
});

it('rolls back the whole file when a model rejects a write after preview', function () {
    $rows = accountImportRows();
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
        $this->assertDatabaseCount('account_assignment_histories', 0);
    } finally {
        AccountMaster::setEventDispatcher($dispatcher);
    }
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
        expect($sheets)->toBe(['Accounts', 'Instructions', 'References'])->and($this->service->readFile($path, true))->toBe([]);
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
            ->call('previewAccounts')->assertHasNoErrors()->assertSee('Review account import');
        $this->assertDatabaseCount('account_masters', 0);
        $page->call('importAccounts')->assertHasNoErrors()->assertSet('accountPreview', [])->assertSee('1 accounts created');
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
