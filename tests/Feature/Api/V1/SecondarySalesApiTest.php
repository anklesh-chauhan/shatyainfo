<?php

use App\Filament\Pages\SecondarySalesPreferences;
use App\Filament\Resources\SecondarySalesStatements\Pages\ManageSecondarySalesStatements;
use App\Filament\Resources\SkuUnitConversions\Pages\ManageSkuUnitConversions;
use App\Models\SecondarySalesStatement;
use App\Models\SkuUnitConversion;
use App\Services\Field\SecondarySalesService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->rep = reportingUser('SecondaryUploader');
    $this->rsm = reportingUser('SecondaryRSM');
    foreach (['Create:SecondarySales', 'Review:SecondarySales', 'View:AccountMaster', 'AccessAllRecords'] as $name) {
        Permission::findOrCreate($name, 'web');
    }
    $this->rep->givePermissionTo(['Create:SecondarySales', 'View:AccountMaster', 'AccessAllRecords']);
    $this->rsm->givePermissionTo('Review:SecondarySales');
    $territory = reportingTerritory('Secondary Territory');
    $this->stockist = reportingDoctor($this->rep, 'Stockist Alpha');
    $this->stockist->update(['territory_id' => $territory->id, 'accept_secondary_statements' => true]);
    $this->one = reportingSampleItem('SECOND-ONE', 'Secondary Product One');
    $this->two = reportingSampleItem('SECOND-TWO', 'Secondary Product Two');
    foreach ([$this->one, $this->two] as $item) {
        SkuUnitConversion::query()->create(['item_master_id' => $item->id, 'unit' => 'box', 'base_units' => 100]);
    }
    $this->data = ['stockist_id' => $this->stockist->id, 'territory_id' => $territory->id, 'reviewer_id' => $this->rsm->id, 'period' => today()->format('Y-m')];
    Sanctum::actingAs($this->rep);
});

function secondaryCsv(array $rows): string
{
    return implode(',', SecondarySalesService::HEADERS)."\n".implode("\n", array_map(fn ($row) => implode(',', $row), $rows))."\n";
}

it('normalizes multi-SKU invoice groups once and requires separate physical return verification', function () {
    $rows = [
        ['INV-1', today()->toDateString(), 'B1', $this->one->item_code, 'box', 2, '1000.00', '50.00', 'sale', ''],
        ['INV-1', today()->toDateString(), 'B1', $this->two->item_code, 'box', 1, '500.00', '0', 'sale', ''],
        ['CN-1', today()->toDateString(), 'B1', $this->one->item_code, 'box', 1, '100.00', '0', 'return', 'PHYS-1'],
    ];
    $csv = secondaryCsv($rows);
    $send = fn () => $this->post('/api/v1/secondary-sales', [...$this->data, 'file' => UploadedFile::fake()->createWithContent('statement.csv', $csv)], ['Accept' => 'application/json']);
    $result = $send()->assertCreated()->assertJsonPath('data.status', 'pending_review');
    $id = $result->json('data.id');
    $send()->assertCreated()->assertJsonPath('data.id', $id);
    expect(SecondarySalesStatement::query()->count())->toBe(1);
    $statement = SecondarySalesStatement::query()->with('lines')->sole();
    expect((float) $statement->lines[0]->base_quantity)->toBe(200.0)
        ->and((int) $statement->lines[0]->gross_paise)->toBe(100000)
        ->and($statement->lines[0]->errors)->toBe([])->and($statement->lines[1]->errors)->toBe([]);
    $this->postJson('/api/v1/secondary-sales/'.$id.'/review', ['decision' => 'approved', 'note' => 'Own review'])->assertForbidden();
    Sanctum::actingAs($this->rsm);
    $this->postJson('/api/v1/secondary-sales/'.$id.'/review', ['decision' => 'approved', 'note' => 'Invoice checked'])->assertUnprocessable();
    expect($statement->fresh()->status)->toBe('pending_review');
    $this->postJson('/api/v1/secondary-sales/'.$id.'/review', ['decision' => 'approved', 'note' => 'Verified physical return against PHYS-1',
        'physically_verified_line_ids' => [$statement->lines[2]->id]])->assertSuccessful()->assertJsonPath('data.status', 'approved');
    expect(DB::table('secondary_sales_groups')->count())->toBe(2)
        ->and($statement->lines[2]->fresh()->physically_verified)->toBeTrue();
    $summary = app(SecondarySalesService::class)->summary($this->stockist->territory_id, today()->startOfMonth()->toDateString(), today()->endOfMonth()->toDateString());
    expect($summary['net_paise'])->toBe(135000)->and($summary['net_base_quantity'])->toBe(200.0);
    Sanctum::actingAs($this->rep);
    $rows[0][6] = '1100.00';
    $duplicate = $this->post('/api/v1/secondary-sales', [...$this->data, 'file' => UploadedFile::fake()->createWithContent('duplicate.csv', secondaryCsv($rows))], ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonPath('data.status', 'reconciliation')->json('data.id');
    Sanctum::actingAs($this->rsm);
    $this->postJson('/api/v1/secondary-sales/'.$duplicate.'/review', ['decision' => 'approved', 'note' => 'Duplicate reviewed',
        'physically_verified_line_ids' => SecondarySalesStatement::findOrFail($duplicate)->lines()->where('transaction_type', 'return')->pluck('id')->all()])->assertConflict();
});

it('renders the import, review and unit-conversion screens under explicit permissions', function () {
    Permission::findOrCreate('Manage:SkuUnitConversion', 'web');
    $this->rep->givePermissionTo('Manage:SkuUnitConversion');
    $this->rep->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($this->rep);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(ManageSecondarySalesStatements::class)->assertSuccessful();
    Livewire::test(ManageSkuUnitConversions::class)->assertSuccessful();
    Livewire::test(SecondarySalesPreferences::class)->assertSuccessful();
});

it('retains unmatched rows for reconciliation and supports the same template as Excel', function () {
    $path = tempnam(sys_get_temp_dir(), 'secondary-xlsx-');
    try {
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(SecondarySalesService::HEADERS));
        $writer->addRow(Row::fromValues(['XLSX-1', today()->toDateString(), 'B2', 'UNKNOWN-SKU', 'strip', 2, 100.50, 0, 'sale', '']));
        $writer->close();
        $result = $this->post('/api/v1/secondary-sales', [...$this->data, 'file' => UploadedFile::fake()->createWithContent('statement.xlsx', file_get_contents($path))], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.status', 'reconciliation');
        $id = $result->json('data.id');
        $line = SecondarySalesStatement::findOrFail($id)->lines()->sole();
        expect($line->errors)->toContain('unknown_sku', 'missing_unit_conversion');
        Sanctum::actingAs($this->rsm);
        $this->postJson('/api/v1/secondary-sales/'.$id.'/review', ['decision' => 'approved', 'note' => 'Cannot approve invalid data'])->assertUnprocessable();
        $this->postJson('/api/v1/secondary-sales/'.$id.'/review', ['decision' => 'rejected', 'note' => 'Correct SKU mapping and reupload'])->assertSuccessful();
        $fixed = reportingSampleItem('FIXED-SKU', 'Mapped Product');
        $fixed->update(['item_code' => 'UNKNOWN-SKU']);
        SkuUnitConversion::query()->create(['item_master_id' => $fixed->id, 'unit' => 'strip', 'base_units' => 10]);
        Sanctum::actingAs($this->rep);
        $this->post('/api/v1/secondary-sales', [...$this->data, 'file' => UploadedFile::fake()->createWithContent('statement.xlsx', file_get_contents($path))], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.status', 'pending_review');
        expect(SecondarySalesStatement::query()->count())->toBe(2);
    } finally {
        @unlink($path);
    }
});
