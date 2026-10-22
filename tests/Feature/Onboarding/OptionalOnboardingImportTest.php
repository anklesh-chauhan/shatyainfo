<?php

use App\Filament\Pages\FieldTravelImport;
use App\Filament\Pages\Onboarding;
use App\Filament\Pages\OnboardingImports;
use App\Models\ApprovalFlow;
use App\Models\ApprovalSetting;
use App\Models\Employee;
use App\Models\EmployeeShift;
use App\Models\Holiday;
use App\Models\InventoryAdjustment;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\ItemMaster;
use App\Models\LeaveBalance;
use App\Models\LocationMaster;
use App\Models\NumberSeries;
use App\Models\Organization;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Attendance\LeaveBalanceCalculator;
use App\Services\DataImport\OptionalOnboardingImport;
use App\Services\DataImport\OptionalOnboardingTemplate;
use App\Services\Reports\LeaveLedgerQuery;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    config(['multitenancy.switch_tenant_tasks' => []]);
    $tenant = new Tenant;
    $tenant->forceFill(['id' => 99999, 'name' => 'Import Test', 'master_setup_status' => 'ready']);
    app('db')->connection('tenant')->setPdo(app('db')->connection()->getPdo())->setReadPdo(app('db')->connection()->getPdo());
    $tenant->makeCurrent();
    auth()->user()->assignRole(Role::findOrCreate('super_admin', 'tenant'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Organization::create(['name' => 'Import Company', 'metadata' => ['company_setup' => ['organization_saved' => true]]]);
    $this->importer = app(OptionalOnboardingImport::class);
});

afterEach(function () {
    Tenant::forgetCurrent();
});

function optionalImportRows(array ...$data): array
{
    return array_map(fn ($row, $index) => ['line' => $index + 2, 'data' => $row], $data, array_keys($data));
}

it('renders the same inline upload and preview workflow in all four onboarding cards', function () {
    Gate::before(fn () => true);
    $page = Livewire::withQueryParams(['section' => 'field-travel'])->test(Onboarding::class)->assertSet('step', 4)
        ->assertSee('Optional setup imports')
        ->assertSeeLivewire(FieldTravelImport::class)
        ->assertSeeLivewire(OnboardingImports::class);
    expect(substr_count($page->html(), 'Preview file'))->toBe(4)
        ->and(substr_count($page->html(), 'Download Excel template'))->toBe(4)
        ->and(substr_count($page->html(), 'Upload your completed file'))->toBe(4);
});

it('keeps embedded import groups independent and resets preview when the type changes', function () {
    $csv = implode(',', OptionalOnboardingImport::COLUMNS['products'])."\nEMBED001,Embedded sample,sample,,,,,,,,,,\n";
    Livewire::test(OnboardingImports::class, ['group' => 'products'])
        ->assertSee('Opening Inventory')->assertDontSee('Holiday Calendar')
        ->call('downloadTemplate')->assertFileDownloaded('products-setup.xlsx')
        ->set('file', UploadedFile::fake()->createWithContent('products.csv', $csv))
        ->call('previewFile')->assertHasNoErrors()->assertSee('Confirm import')
        ->set('module', 'inventory_openings')->assertSet('preview', [])->assertSet('file', null);
    Livewire::test(OnboardingImports::class, ['group' => 'hr'])
        ->assertSet('module', 'shifts')->assertSee('Holiday Calendar')->assertDontSee('Opening Inventory')
        ->call('downloadTemplate')->assertFileDownloaded('shifts-setup.xlsx')
        ->call('importReviewed')->assertHasErrors('file');
    Gate::before(fn () => true);
    Livewire::test(FieldTravelImport::class, ['embedded' => true])
        ->assertSet('fromOnboarding', true)->assertSee('Preview file')
        ->call('downloadTemplate')->assertFileDownloaded('patches-and-fares.xlsx')
        ->assertDontSee('Return to company setup')->call('previewFile')->assertHasErrors('file');
});

it('imports leave openings once and includes them in dated balances and the ledger', function () {
    $employee = reportingUser('Opening Employee')->employee;
    $type = reportingLeaveType('Casual');
    $date = today()->subDays(10);
    $rows = optionalImportRows(['employee_code' => $employee->employee_id, 'leave_type_code' => $type->code, 'opening_balance' => '12.50', 'effective_date' => $date->toDateString(), 'period_end' => today()->endOfYear()->toDateString()]);
    $preview = $this->importer->preview('leave_openings', $rows);
    expect($preview['errors'])->toBe([])->and(LeaveBalance::count())->toBe(0);
    $this->importer->import('leave_openings', $rows, $preview['fingerprint']);
    $calculator = app(LeaveBalanceCalculator::class);
    expect((float) $calculator->calculate($employee->id, $type->id, $date)['closing'])->toBe(12.5)
        ->and((float) $calculator->calculate($employee->id, $type->id, $date->copy()->subDay())['closing'])->toBe(0.0);
    $ledger = app(LeaveLedgerQuery::class)->unionQuery()->get();
    expect($ledger)->toHaveCount(1)->and($ledger->first()->source)->toBe('Opening Balance')->and((float) $ledger->first()->amount)->toBe(12.5);
    $preview = $this->importer->preview('leave_openings', $rows);
    expect($preview['errors'])->toBe([]);
    expect($this->importer->import('leave_openings', $rows, $preview['fingerprint'])['unchanged'])->toBe(1);
    $rows[0]['data']['opening_balance'] = '20';
    expect($this->importer->preview('leave_openings', $rows)['errors'])->toHaveCount(1);
    expect(LeaveBalance::count())->toBe(1);
});

it('posts dated opening inventory once and rejects changes and existing stock', function () {
    $item = ItemMaster::create(['sku' => 'OPEN001', 'item_name' => 'Opening Item']);
    $location = reportingLocation('Opening Warehouse');
    $date = today()->subDays(5)->toDateString();
    $rows = optionalImportRows(['sku' => $item->sku, 'location_code' => $location->location_code, 'quantity' => '10.500', 'unit_cost' => '4.0000', 'effective_date' => $date]);
    $preview = $this->importer->preview('inventory_openings', $rows);
    expect($preview['errors'])->toBe([])->and(InventoryStock::count())->toBe(0)->and(InventoryAdjustment::count())->toBe(0);
    $this->importer->import('inventory_openings', $rows, $preview['fingerprint']);
    expect((float) InventoryStock::sole()->quantity_on_hand)->toBe(10.5)
        ->and(InventoryAdjustment::sole()->isPosted())->toBeTrue()
        ->and(InventoryMovement::sole()->movement_at->toDateString())->toBe($date)
        ->and((float) InventoryMovement::sole()->total_value)->toBe(42.0);
    $preview = $this->importer->preview('inventory_openings', $rows);
    expect($preview['errors'])->toBe([]);
    expect($this->importer->import('inventory_openings', $rows, $preview['fingerprint'])['unchanged'])->toBe(1);
    expect(InventoryMovement::count())->toBe(1);
    $rows[0]['data']['quantity'] = '20';
    expect($this->importer->preview('inventory_openings', $rows)['errors'])->toHaveCount(1);
    $other = reportingLocation('Existing Warehouse');
    InventoryStock::create(['item_master_id' => $item->id, 'location_master_id' => $other->id, 'quantity_on_hand' => 1]);
    $rows[0]['data']['location_code'] = $other->location_code;
    expect($this->importer->preview('inventory_openings', $rows)['errors'][0]['message'])->toContain('history already exists');
});

it('respects inventory approval requirements and validates opening values', function () {
    $item = ItemMaster::create(['sku' => 'APP001', 'item_name' => 'Approval Item']);
    $location = reportingLocation('Approval Warehouse');
    $rows = optionalImportRows(['sku' => $item->sku, 'location_code' => $location->location_code, 'quantity' => '10', 'unit_cost' => '4', 'effective_date' => today()->toDateString()]);
    $setting = ApprovalSetting::firstOrCreate([], ['enabled_modules' => []]);
    $setting->update(['enabled_modules' => [InventoryAdjustment::class]]);
    expect($this->importer->preview('inventory_openings', $rows)['errors'][0]['message'])->toContain('require approval');
    $rows[0]['data']['quantity'] = '999999999';
    $rows[0]['data']['unit_cost'] = '99999999';
    expect($this->importer->preview('inventory_openings', $rows)['errors'][0]['message'])->toContain('value exceeds');
    expect(InventoryAdjustment::count())->toBe(0)->and(InventoryMovement::count())->toBe(0);
});

it('previews without writes and imports products with taxes safely on repeat upload', function () {
    $tax = Tax::create(['name' => 'GST 12', 'total_rate' => 12, 'is_active' => true]);
    $rows = optionalImportRows(['sku' => 'SMP001', 'item_name' => 'Sample One', 'item_type' => 'sample', 'tax_names' => 'GST 12', 'selling_price' => '20.00']);
    $before = NumberSeries::count();
    $preview = $this->importer->preview('products', $rows);
    expect($preview['errors'])->toBe([])->and(ItemMaster::count())->toBe(0)->and(NumberSeries::count())->toBe($before);
    expect($this->importer->import('products', $rows, $preview['fingerprint']))->toMatchArray(['created' => 1, 'updated' => 0]);
    $item = ItemMaster::sole();
    expect($item->sku)->toBe('SMP001')->and($item->item_code)->not->toBeEmpty()
        ->and($item->taxes()->pluck('taxes.id')->all())->toBe([$tax->id])
        ->and((float) $item->tax_rate)->toBe(12.0);
    $preview = $this->importer->preview('products', $rows);
    expect($preview['plans'][0]['action'])->toBe('unchanged');
    expect($this->importer->import('products', $rows, $preview['fingerprint'])['unchanged'])->toBe(1);
    expect(ItemMaster::count())->toBe(1);
});

it('rejects unknown references duplicate identities and invalid rows without partial imports', function () {
    $rows = optionalImportRows(
        ['sku' => 'SMP001', 'item_name' => 'Valid item'],
        ['sku' => 'SMP002', 'item_name' => 'Invalid item', 'brand' => 'Missing brand'],
    );
    $preview = $this->importer->preview('products', $rows);
    expect($preview['errors'])->toHaveCount(1);
    expect(fn () => $this->importer->import('products', $rows, $preview['fingerprint']))->toThrow(ValidationException::class);
    expect(ItemMaster::count())->toBe(0);
    $rows = optionalImportRows(['sku' => 'X', 'item_name' => 'One'], ['sku' => 'x', 'item_name' => 'Two']);
    expect($this->importer->preview('products', $rows)['errors'][0]['message'])->toContain('Duplicate');
});

it('requires a fresh preview after existing data changes and protects approved records', function () {
    $rows = optionalImportRows(['sku' => 'SMP001', 'item_name' => 'Before']);
    $preview = $this->importer->preview('products', $rows);
    $this->importer->import('products', $rows, $preview['fingerprint']);
    $rows[0]['data']['item_name'] = 'After';
    $preview = $this->importer->preview('products', $rows);
    ItemMaster::sole()->update(['description' => 'Changed by another user']);
    expect(fn () => $this->importer->import('products', $rows, $preview['fingerprint']))->toThrow(ValidationException::class);
    expect(ItemMaster::sole()->item_name)->toBe('Before');
    $flow = ApprovalFlow::create(['module' => 'ItemMaster', 'active' => false]);
    ItemMaster::sole()->approval()->create(['approval_flow_id' => $flow->id, 'module' => 'ItemMaster', 'requested_by' => auth()->id(), 'approval_status' => 'approved']);
    expect($this->importer->preview('products', $rows)['errors'][0]['message'])->toContain('Approved');
});

it('imports shift periods and rejects existing and within-file overlaps', function () {
    $employee = Employee::withoutEvents(fn () => Employee::create(['employee_id' => 'EMP001', 'first_name' => 'Test', 'last_name' => 'User', 'mobile_number' => '9000000111', 'is_active' => true]));
    $shift = reportingShift('Day Shift');
    $rows = optionalImportRows(['employee_code' => 'EMP001', 'shift_code' => $shift->code, 'effective_from' => '2026-01-01', 'effective_to' => '2026-06-30']);
    $preview = $this->importer->preview('shifts', $rows);
    expect($preview['errors'])->toBe([])->and(EmployeeShift::count())->toBe(0);
    $this->importer->import('shifts', $rows, $preview['fingerprint']);
    expect(EmployeeShift::sole()->employee_id)->toBe($employee->id);
    expect($this->importer->preview('shifts', $rows)['plans'][0]['action'])->toBe('unchanged');
    $overlap = optionalImportRows(['employee_code' => 'EMP001', 'shift_code' => $shift->code, 'effective_from' => '2026-06-01']);
    expect($this->importer->preview('shifts', $overlap)['errors'][0]['message'])->toContain('overlap');
    $overlap = optionalImportRows(
        ['employee_code' => 'EMP001', 'shift_code' => $shift->code, 'effective_from' => '2026-07-01'],
        ['employee_code' => 'EMP001', 'shift_code' => $shift->code, 'effective_from' => '2026-08-01'],
    );
    expect($this->importer->preview('shifts', $overlap)['errors'][0]['message'])->toContain('row 2');
});

it('imports holidays by date and scope without duplicating reruns', function () {
    $location = LocationMaster::create(['name' => 'Office', 'location_code' => 'OFFICE', 'is_active' => true]);
    $rows = optionalImportRows(['date' => '2026-12-25', 'name' => 'Holiday', 'location_code' => $location->location_code, 'is_optional' => 'yes']);
    $preview = $this->importer->preview('holidays', $rows);
    expect($preview['errors'])->toBe([]);
    $this->importer->import('holidays', $rows, $preview['fingerprint']);
    expect(Holiday::sole()->location_master_id)->toBe($location->id)->and(Holiday::sole()->is_optional)->toBeTrue();
    expect($this->importer->preview('holidays', $rows)['plans'][0]['action'])->toBe('unchanged');
    $rows[0]['data']['name'] = 'Renamed holiday';
    $preview = $this->importer->preview('holidays', $rows);
    $this->importer->import('holidays', $rows, $preview['fingerprint']);
    expect(Holiday::count())->toBe(1)->and(Holiday::sole()->name)->toBe('Renamed holiday');
});

it('provides blank workbooks with separate instructions examples and references', function (string $module) {
    $path = tempnam(sys_get_temp_dir(), 'optional-template-');
    $reader = new Reader;
    try {
        app(OptionalOnboardingTemplate::class)->write($module, $path);
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $sheets[$sheet->getName()] = [];
            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = $row->toArray();
            }
        }
        expect(array_keys($sheets))->toBe([OptionalOnboardingImport::SHEETS[$module], 'Instructions', 'Examples', 'References']);
        expect($sheets[OptionalOnboardingImport::SHEETS[$module]])->toHaveCount(1);
        $reader->close();
        expect($this->importer->read($module, $path, true))->toBe([]);
    } finally {
        $reader->close();
        unlink($path);
    }
})->with(['products', 'shifts', 'holidays', 'leave_openings', 'inventory_openings']);

it('requires preview before confirmation and resets it when the upload changes', function () {
    $csv = implode(',', OptionalOnboardingImport::COLUMNS['products'])."\nSMP001,Sample,sample,,,,,,,,,,\n";
    Livewire::test(OnboardingImports::class)
        ->assertSee('Download Excel template')
        ->call('importReviewed')->assertHasErrors('file')
        ->set('file', UploadedFile::fake()->createWithContent('products.csv', $csv))
        ->call('previewFile')->assertHasNoErrors()->assertSee('SMP001')
        ->set('module', 'holidays')->assertSet('rows', [])->assertSet('preview', []);
    expect(ItemMaster::count())->toBe(0);
});

it('rejects unauthorized imports', function () {
    $this->actingAs(User::factory()->create());
    expect(OnboardingImports::canAccess())->toBeFalse();
    expect(fn () => $this->importer->preview('products', optionalImportRows(['sku' => 'X', 'item_name' => 'Item'])))->toThrow(HttpException::class);
});

it('confirms a reviewed upload through the page and prevents replay', function () {
    $csv = implode(',', OptionalOnboardingImport::COLUMNS['products'])."\nPAGE001,Page sample,sample,,,,,,,,,,\n";
    Livewire::test(OnboardingImports::class)
        ->set('file', UploadedFile::fake()->createWithContent('products.csv', $csv))
        ->call('previewFile')->assertHasNoErrors()
        ->call('importReviewed')->assertHasNoErrors()
        ->assertSet('result.created', 1)->assertSet('rows', [])
        ->call('importReviewed')->assertHasErrors('file');
    expect(ItemMaster::where('sku', 'PAGE001')->count())->toBe(1);
});
