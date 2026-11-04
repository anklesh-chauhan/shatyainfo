<?php

use App\Filament\Pages\SecondarySalesPreferences;
use App\Filament\Resources\SecondarySalesStatements\Pages\ManageSecondarySalesStatements;
use App\Models\ContactDetail;
use App\Models\SalesInvoice;
use App\Models\SecondarySalesPreference;
use App\Models\SecondarySalesStatement;
use App\Models\SkuUnitConversion;
use App\Services\Field\SecondarySalesBalanceService;
use App\Services\Field\SecondarySalesService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->rep = reportingUser('BalanceUploader');
    $this->rsm = reportingUser('BalanceRSM');
    foreach (['Create:SecondarySales', 'Review:SecondarySales', 'Manage:SecondarySalesPreference', 'View:AccountMaster', 'AccessAllRecords'] as $name) {
        Permission::findOrCreate($name, 'web');
    }
    $this->rep->givePermissionTo(['Create:SecondarySales', 'View:AccountMaster', 'AccessAllRecords']);
    $this->rsm->givePermissionTo('Review:SecondarySales');
    $territory = reportingTerritory('Balance Territory');
    $this->stockist = reportingDoctor($this->rep, 'Vardhman Pharma Distributors');
    $this->stockist->update(['territory_id' => $territory->id, 'accept_secondary_statements' => true]);
    $this->one = reportingSampleItem('AM-501', 'Amoxiclav 625mg');
    $this->two = reportingSampleItem('DER-102', 'DermaClenz Wash');
    $this->three = reportingSampleItem('CARD-05', 'TelmiShat 40mg');
    foreach ([$this->one, $this->two, $this->three] as $item) {
        SkuUnitConversion::query()->create(['item_master_id' => $item->id, 'unit' => 'box', 'base_units' => 1]);
    }
    $this->period = today()->format('Y-m');
    $this->data = ['stockist_id' => $this->stockist->id, 'territory_id' => $territory->id, 'reviewer_id' => $this->rsm->id, 'period' => $this->period];
    Sanctum::actingAs($this->rep);
});

function balanceRow($test, array $overrides = []): array
{
    $month = CarbonImmutable::createFromFormat('!Y-m', $test->period);
    $row = [
        'stockist_code' => $test->stockist->account_code,
        'period_month_year' => $month->format('m-Y'),
        'product_code' => $test->one->item_code,
        'batch_number' => 'B-4012',
        'expiry_date' => $month->addYear()->format('m-Y'),
        'pts_price' => '120.00',
        'opening_qty' => '50',
        'purchase_qty' => '200',
        'primary_free_qty' => '20',
        'purchase_return_qty' => '0',
        'secondary_sales_qty' => '180',
        'secondary_free_qty' => '18',
        'sales_return_qty' => '5',
        'expiry_breakage_qty' => '0',
        'reported_closing_qty' => '77',
    ];

    return array_map(fn (string $header) => (string) (array_merge($row, $overrides)[$header]), SecondarySalesBalanceService::HEADERS);
}

function balanceCsv(array $rows): string
{
    return implode(',', SecondarySalesBalanceService::HEADERS)."\n".implode("\n", array_map(fn ($row) => implode(',', $row), $rows))."\n";
}

function uploadBalance($test, array $rows, array $data = [])
{
    return $test->post('/api/v1/secondary-sales', [...$test->data, ...$data, 'file' => UploadedFile::fake()->createWithContent('balances.csv', balanceCsv($rows))], ['Accept' => 'application/json']);
}

it('reconciles the representative stock statement, values paid secondary sales and blocks closing variance', function () {
    $rows = [
        balanceRow($this),
        balanceRow($this, ['product_code' => $this->two->item_code, 'batch_number' => 'B-9921', 'pts_price' => '85.00',
            'opening_qty' => '120', 'purchase_qty' => '0', 'primary_free_qty' => '0', 'secondary_sales_qty' => '45',
            'secondary_free_qty' => '0', 'sales_return_qty' => '0', 'expiry_breakage_qty' => '5', 'reported_closing_qty' => '70']),
        balanceRow($this, ['product_code' => $this->three->item_code, 'batch_number' => 'B-1102', 'pts_price' => '42.00',
            'opening_qty' => '300', 'purchase_qty' => '500', 'primary_free_qty' => '50', 'secondary_sales_qty' => '620',
            'secondary_free_qty' => '62', 'sales_return_qty' => '10', 'expiry_breakage_qty' => '0', 'reported_closing_qty' => '175']),
    ];
    $this->get('/api/v1/secondary-sales/template?kind=stock_balance')->assertSuccessful()->assertSee('stockist_code,period_month_year', false);
    $id = uploadBalance($this, $rows)->assertCreated()->assertJsonPath('data.status', 'reconciliation')->assertJsonPath('data.source_kind', 'stock_balance')->json('data.id');
    $statement = SecondarySalesStatement::query()->with('balances')->findOrFail($id);
    expect($statement->balances)->toHaveCount(3)
        ->and($statement->balances[0]->status)->toBe('matched')->and((float) $statement->balances[0]->calculated_closing_qty)->toBe(77.0)
        ->and($statement->balances[1]->status)->toBe('matched')->and((float) $statement->balances[1]->calculated_closing_qty)->toBe(70.0)
        ->and($statement->balances[2]->status)->toBe('discrepancy')->and((float) $statement->balances[2]->calculated_closing_qty)->toBe(178.0)
        ->and((float) $statement->balances[2]->variance_qty)->toBe(-3.0)
        ->and($statement->balances[2]->errors)->toContain('stock_balance_variance');
    Sanctum::actingAs($this->rsm);
    $this->postJson('/api/v1/secondary-sales/'.$id.'/review', ['decision' => 'approved', 'note' => 'Variance present',
        'physically_verified_line_ids' => $statement->lines()->where('transaction_type', '!=', 'sale')->pluck('id')->all()])->assertUnprocessable();
    $this->postJson('/api/v1/secondary-sales/'.$id.'/review', ['decision' => 'rejected', 'note' => 'Correct CARD-05 closing'])->assertSuccessful();
    Sanctum::actingAs($this->rep);
    $matched = uploadBalance($this, array_slice($rows, 0, 2))->assertCreated()->assertJsonPath('data.status', 'pending_review')->json('data.id');
    $approved = SecondarySalesStatement::query()->findOrFail($matched);
    Sanctum::actingAs($this->rsm);
    $this->postJson('/api/v1/secondary-sales/'.$matched.'/review', ['decision' => 'approved', 'note' => 'Matched opening, purchases, free goods and closing',
        'physically_verified_line_ids' => $approved->lines()->where('transaction_type', '!=', 'sale')->pluck('id')->all()])->assertSuccessful();
    $summary = app(SecondarySalesService::class)->summary($this->stockist->territory_id, today()->startOfMonth()->toDateString(), today()->endOfMonth()->toDateString());
    expect($summary['gross_paise'])->toBe(2160000 + 382500)->and($summary['returns_paise'])->toBe(60000)
        ->and($summary['breakage_expiry_paise'])->toBe(42500)->and($summary['net_paise'])->toBe(2440000)
        ->and($summary['net_base_quantity'])->toBe(215.0);
});

it('applies configurable tolerances, formula signs, opening carry-forward and exclusive templates', function () {
    SecondarySalesPreference::current()->update(['variance_tolerance_qty' => 3]);
    $card = [balanceRow($this, ['product_code' => $this->three->item_code, 'batch_number' => 'B-1102', 'pts_price' => '42.00',
        'opening_qty' => '300', 'purchase_qty' => '500', 'primary_free_qty' => '50', 'secondary_sales_qty' => '620',
        'secondary_free_qty' => '62', 'sales_return_qty' => '10', 'expiry_breakage_qty' => '0', 'reported_closing_qty' => '175'])];
    $id = uploadBalance($this, $card)->assertCreated()->assertJsonPath('data.status', 'pending_review')->json('data.id');
    expect(SecondarySalesStatement::query()->findOrFail($id)->balances()->sole()->status)->toBe('matched');
    Sanctum::actingAs($this->rsm);
    $this->postJson('/api/v1/secondary-sales/'.$id.'/review', ['decision' => 'rejected', 'note' => 'Reset tolerance example'])->assertSuccessful();
    SecondarySalesPreference::current()->update(['variance_tolerance_qty' => 0, 'formula_signs' => [...SecondarySalesPreference::DEFAULT_FORMULA_SIGNS, 'sales_return_qty' => -1]]);
    Sanctum::actingAs($this->rep);
    $flipped = uploadBalance($this, [balanceRow($this)])->assertCreated()->assertJsonPath('data.status', 'reconciliation')->json('data.id');
    expect(SecondarySalesStatement::query()->findOrFail($flipped)->balances()->sole()->errors)->toContain('stock_balance_variance');
    Sanctum::actingAs($this->rsm);
    $this->postJson('/api/v1/secondary-sales/'.$flipped.'/review', ['decision' => 'rejected', 'note' => 'Restore default formula'])->assertSuccessful();
    SecondarySalesPreference::current()->update(['formula_signs' => SecondarySalesPreference::DEFAULT_FORMULA_SIGNS]);
    Sanctum::actingAs($this->rep);
    $priorPeriod = CarbonImmutable::createFromFormat('!Y-m', $this->period)->subMonth();
    $first = uploadBalance($this, [balanceRow($this, ['period_month_year' => $priorPeriod->format('m-Y')])], ['period' => $priorPeriod->format('Y-m')])
        ->assertCreated()->json('data.id');
    $prior = SecondarySalesStatement::query()->findOrFail($first);
    Sanctum::actingAs($this->rsm);
    $this->postJson('/api/v1/secondary-sales/'.$first.'/review', ['decision' => 'approved', 'note' => 'Prior month matched',
        'physically_verified_line_ids' => $prior->lines()->where('transaction_type', '!=', 'sale')->pluck('id')->all()])->assertSuccessful();
    Sanctum::actingAs($this->rep);
    $wrongOpening = uploadBalance($this, [balanceRow($this, ['opening_qty' => '70', 'reported_closing_qty' => '97'])])
        ->assertCreated()->assertJsonPath('data.status', 'reconciliation')->json('data.id');
    expect(SecondarySalesStatement::query()->findOrFail($wrongOpening)->balances()->sole()->errors)->toContain('opening_does_not_match_prior_closing');
    $invoiceCsv = implode(',', SecondarySalesService::HEADERS)."\nINV-1,".today()->toDateString().',B9,'.$this->one->item_code.",box,1,100,0,sale,\n";
    $this->post('/api/v1/secondary-sales', [...$this->data, 'file' => UploadedFile::fake()->createWithContent('invoices.csv', $invoiceCsv)], ['Accept' => 'application/json'])
        ->assertConflict();
});

it('freezes accepted variance from incentives and can deduct secondary free goods as schemes', function () {
    SecondarySalesPreference::current()->update([
        'approvable_discrepancy_codes' => ['stock_balance_variance'],
        'freeze_incentive_on_variance' => true,
    ]);
    $id = uploadBalance($this, [balanceRow($this, ['product_code' => $this->three->item_code, 'batch_number' => 'B-1102', 'pts_price' => '42.00',
        'opening_qty' => '300', 'purchase_qty' => '500', 'primary_free_qty' => '50', 'secondary_sales_qty' => '620',
        'secondary_free_qty' => '62', 'sales_return_qty' => '10', 'expiry_breakage_qty' => '0', 'reported_closing_qty' => '175'])])
        ->assertCreated()->json('data.id');
    $statement = SecondarySalesStatement::query()->findOrFail($id);
    Sanctum::actingAs($this->rsm);
    $this->postJson('/api/v1/secondary-sales/'.$id.'/review', ['decision' => 'approved', 'note' => 'Accept three-unit shortage and freeze incentives',
        'physically_verified_line_ids' => $statement->lines()->where('transaction_type', '!=', 'sale')->pluck('id')->all()])->assertSuccessful();
    expect($statement->lines()->where('incentive_frozen', true)->count())->toBeGreaterThan(0)
        ->and(app(SecondarySalesService::class)->summary($this->stockist->territory_id, today()->startOfMonth()->toDateString(), today()->endOfMonth()->toDateString())['net_paise'])->toBe(0);
    SecondarySalesPreference::current()->update([
        'approvable_discrepancy_codes' => [],
        'include_secondary_free_in_scheme_deduction' => true,
        'exclusive_source_kind_per_period' => false,
    ]);
    Sanctum::actingAs($this->rep);
    $schemeId = uploadBalance($this, [balanceRow($this)])->assertCreated()->json('data.id');
    $scheme = SecondarySalesStatement::query()->findOrFail($schemeId);
    Sanctum::actingAs($this->rsm);
    $this->postJson('/api/v1/secondary-sales/'.$schemeId.'/review', ['decision' => 'approved', 'note' => 'Free goods deducted as scheme',
        'physically_verified_line_ids' => $scheme->lines()->where('transaction_type', '!=', 'sale')->pluck('id')->all()])->assertSuccessful();
    expect($scheme->lines()->where('transaction_type', 'sale')->sole()->discount_paise)->toBe(216000)
        ->and(app(SecondarySalesService::class)->summary($this->stockist->territory_id, today()->startOfMonth()->toDateString(), today()->endOfMonth()->toDateString())['discount_paise'])->toBe(216000);
});

it('optionally matches primary purchases and renders the configurable rules page', function () {
    SecondarySalesPreference::current()->update(['verify_primary_purchases' => true]);
    $contact = ContactDetail::forceCreate(['first_name' => 'Stockist Buyer']);
    $invoice = SalesInvoice::forceCreate(['document_number' => 'PRI-200', 'account_master_id' => $this->stockist->id,
        'contact_detail_id' => $contact->id, 'date' => today(), 'status' => 'draft', 'approval_status' => 'draft', 'total' => 1, 'currency' => 'INR']);
    $invoice->items()->create(['item_master_id' => $this->one->id, 'quantity' => 150, 'price' => 0]);
    $invoice->updateQuietly(['status' => 'sent', 'approval_status' => 'approved']);
    $mismatch = uploadBalance($this, [balanceRow($this)])->assertCreated()->json('data.id');
    expect(SecondarySalesStatement::query()->findOrFail($mismatch)->balances()->sole()->errors)->toContain('primary_purchase_mismatch');
    $this->rep->givePermissionTo('Manage:SecondarySalesPreference');
    $this->rep->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($this->rep);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(SecondarySalesPreferences::class)->assertSuccessful()
        ->fillForm(['variance_tolerance_qty' => 1])->call('save')->assertNotified();
    expect((float) SecondarySalesPreference::current()->variance_tolerance_qty)->toBe(1.0);
    Livewire::test(ManageSecondarySalesStatements::class)->assertSuccessful();
});
