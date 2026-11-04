<?php

use App\Filament\Resources\SecondarySalesCorrections\Pages\ManageSecondarySalesCorrections;
use App\Models\SecondarySalesCorrection;
use App\Models\SecondarySalesStatement;
use App\Models\SkuUnitConversion;
use App\Services\Field\SecondarySalesService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->rep = reportingUser('CorrectionUploader');
    $this->rsm = reportingUser('CorrectionRSM');
    $this->other = reportingUser('CorrectionOtherRSM');
    foreach (['Create:SecondarySales', 'Review:SecondarySales', 'View:AccountMaster', 'AccessAllRecords'] as $name) {
        Permission::findOrCreate($name, 'web');
    }
    $this->rep->givePermissionTo(['Create:SecondarySales', 'View:AccountMaster', 'AccessAllRecords']);
    $this->rsm->givePermissionTo('Review:SecondarySales');
    $this->other->givePermissionTo('Review:SecondarySales');
    $this->territory = reportingTerritory('Correction Territory');
    $this->stockist = reportingDoctor($this->rep, 'Stockist Beta');
    $this->stockist->update(['territory_id' => $this->territory->id, 'accept_secondary_statements' => true]);
    $this->one = reportingSampleItem('CORR-ONE', 'Correction Product One');
    SkuUnitConversion::query()->create(['item_master_id' => $this->one->id, 'unit' => 'box', 'base_units' => 100]);
    $this->period = today()->format('Y-m');
    $this->data = ['stockist_id' => $this->stockist->id, 'territory_id' => $this->territory->id, 'reviewer_id' => $this->rsm->id, 'period' => $this->period];
    $this->rows = [
        ['INV-9', today()->toDateString(), 'B9', $this->one->item_code, 'box', 2, '1000.00', '0', 'sale', ''],
        ['CN-9', today()->toDateString(), 'B9', $this->one->item_code, 'box', 1, '100.00', '0', 'return', 'PHYS-9'],
    ];
    Sanctum::actingAs($this->rep);
});

function correctionCsv(array $rows): string
{
    return implode(',', SecondarySalesService::HEADERS)."\n".implode("\n", array_map(fn ($row) => implode(',', $row), $rows))."\n";
}

function approvedStatement($test): SecondarySalesStatement
{
    $id = $test->post('/api/v1/secondary-sales', [...$test->data, 'file' => UploadedFile::fake()->createWithContent('original.csv', correctionCsv($test->rows))], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
    $statement = SecondarySalesStatement::query()->findOrFail($id);
    Sanctum::actingAs($test->rsm);
    $test->postJson('/api/v1/secondary-sales/'.$id.'/review', ['decision' => 'approved', 'note' => 'Original verified',
        'physically_verified_line_ids' => $statement->lines()->where('transaction_type', 'return')->pluck('id')->all()])->assertSuccessful();
    Sanctum::actingAs($test->rep);

    return $statement->fresh();
}

function ledgerNet($test): int
{
    return app(SecondarySalesService::class)->summary($test->territory->id, today()->startOfMonth()->toDateString(), today()->endOfMonth()->toDateString())['net_paise'];
}

it('replaces an approved statement atomically, moving groups and adjustments without double-counting', function () {
    $original = approvedStatement($this);
    expect(ledgerNet($this))->toBe(90000);
    $corrected = $this->rows;
    $corrected[0][6] = '1200.00';
    $send = fn (array $payload, string $csv) => $this->post('/api/v1/secondary-sales/'.$original->id.'/corrections', [...$payload, 'file' => UploadedFile::fake()->createWithContent('corrected.csv', $csv)], ['Accept' => 'application/json']);
    $send(['kind' => 'replacement', 'reason' => 'Stockist reissued INV-9 with corrected value', 'reviewer_id' => $this->rep->id], correctionCsv($corrected))->assertUnprocessable();
    $send(['kind' => 'replacement', 'reason' => 'Identical upload', 'reviewer_id' => $this->rsm->id], correctionCsv($this->rows))->assertUnprocessable();
    $this->postJson('/api/v1/secondary-sales/'.$original->id.'/corrections', ['kind' => 'reversal', 'reason' => 'x', 'reviewer_id' => $this->rsm->id])->assertUnprocessable();
    $correction = $send(['kind' => 'replacement', 'reason' => 'Stockist reissued INV-9 with corrected value', 'reviewer_id' => $this->rsm->id], correctionCsv($corrected))
        ->assertCreated()->assertJsonPath('data.status', 'pending_review')->assertJsonPath('data.original_summary.net_paise', 90000)->assertJsonPath('data.replacement_summary.gross_paise', 120000);
    $correctionId = $correction->json('data.id');
    $replacement = SecondarySalesStatement::query()->findOrFail($correction->json('data.replacement_statement_id'));
    expect($replacement->status)->toBe('replacement_pending')->and((int) $replacement->replaces_statement_id)->toBe($original->id)
        ->and($replacement->lines()->get()->every(fn ($line) => $line->errors === []))->toBeTrue()
        ->and(ledgerNet($this))->toBe(90000);
    $send(['kind' => 'reversal', 'reason' => 'Second concurrent request', 'reviewer_id' => $this->rsm->id], correctionCsv($corrected))->assertUnprocessable();
    $this->postJson('/api/v1/secondary-sales/'.$original->id.'/corrections', ['kind' => 'reversal', 'reason' => 'Second concurrent request', 'reviewer_id' => $this->rsm->id])->assertConflict();
    $this->postJson('/api/v1/secondary-sales/corrections/'.$correctionId.'/review', ['decision' => 'approved', 'note' => 'Requester approving'])->assertForbidden();

    Sanctum::actingAs($this->rsm);
    $this->postJson('/api/v1/secondary-sales/'.$replacement->id.'/review', ['decision' => 'approved', 'note' => 'Bypass through ordinary review'])->assertConflict();
    $this->postJson('/api/v1/secondary-sales/corrections/'.$correctionId.'/review', ['decision' => 'approved', 'note' => 'Checked reissued invoice'])->assertUnprocessable();
    expect($original->fresh()->status)->toBe('approved')->and(ledgerNet($this))->toBe(90000)->and(DB::table('secondary_sales_groups')->count())->toBe(2);
    $this->postJson('/api/v1/secondary-sales/corrections/'.$correctionId.'/review', ['decision' => 'approved', 'note' => 'Checked reissued invoice and physical return',
        'physically_verified_line_ids' => $replacement->lines()->where('transaction_type', 'return')->pluck('id')->all()])->assertSuccessful()->assertJsonPath('data.status', 'approved');
    $correctionRecord = SecondarySalesCorrection::query()->findOrFail($correctionId);
    expect($original->fresh()->status)->toBe('reversed')->and((int) $original->fresh()->reversed_by_correction_id)->toBe($correctionId)
        ->and($replacement->fresh()->status)->toBe('approved')->and(ledgerNet($this))->toBe(110000)
        ->and(DB::table('secondary_sales_groups')->count())->toBe(2)
        ->and(DB::table('secondary_sales_groups')->where('secondary_sales_statement_id', $replacement->id)->count())->toBe(2)
        ->and(DB::table('secondary_sales_adjustments')->count())->toBe(1)
        ->and($correctionRecord->released_groups)->toHaveCount(2)->and($correctionRecord->released_adjustments)->toHaveCount(1)
        ->and($correctionRecord->replacement_summary['net_paise'])->toBe(110000);
    $this->postJson('/api/v1/secondary-sales/corrections/'.$correctionId.'/review', ['decision' => 'rejected', 'note' => 'Change decision'])->assertConflict();
    expect(fn () => $original->fresh()->update(['review_note' => 'tamper']))->toThrow(HttpException::class)
        ->and(fn () => $correctionRecord->delete())->toThrow(HttpException::class);
    Sanctum::actingAs($this->rep);
    $this->postJson('/api/v1/secondary-sales/'.$original->id.'/corrections', ['kind' => 'reversal', 'reason' => 'Reverse an already reversed statement', 'reviewer_id' => $this->rsm->id])->assertConflict();
    $this->getJson('/api/v1/secondary-sales/corrections')->assertSuccessful()->assertJsonCount(1, 'data')->assertJsonPath('data.0.can_review', false);
});

it('reverses an approved statement after review, releasing its groups for a later corrected upload', function () {
    $original = approvedStatement($this);
    $correctionId = $this->postJson('/api/v1/secondary-sales/'.$original->id.'/corrections', ['kind' => 'reversal', 'reason' => 'Statement belonged to another stockist', 'reviewer_id' => $this->rsm->id])
        ->assertCreated()->assertJsonPath('data.replacement_statement_id', null)->json('data.id');
    $this->post('/api/v1/secondary-sales', [...$this->data, 'file' => UploadedFile::fake()->createWithContent('again.csv', correctionCsv($this->rows))], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.id', $original->id);
    Sanctum::actingAs($this->other);
    $this->postJson('/api/v1/secondary-sales/corrections/'.$correctionId.'/review', ['decision' => 'approved', 'note' => 'Unassigned reviewer'])->assertNotFound();
    Sanctum::actingAs($this->rsm);
    $this->getJson('/api/v1/secondary-sales/corrections')->assertSuccessful()->assertJsonPath('data.0.can_review', true);
    $this->postJson('/api/v1/secondary-sales/corrections/'.$correctionId.'/review', ['decision' => 'approved', 'note' => 'Confirmed with stockist ledger'])->assertSuccessful();
    expect($original->fresh()->status)->toBe('reversed')->and(ledgerNet($this))->toBe(0)
        ->and(DB::table('secondary_sales_groups')->count())->toBe(0)->and(DB::table('secondary_sales_adjustments')->count())->toBe(0);
    Sanctum::actingAs($this->rep);
    $again = $this->post('/api/v1/secondary-sales', [...$this->data, 'file' => UploadedFile::fake()->createWithContent('again.csv', correctionCsv($this->rows))], ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonPath('data.status', 'pending_review');
    expect($again->json('data.id'))->not->toBe($original->id);
    Sanctum::actingAs($this->rsm);
    $this->postJson('/api/v1/secondary-sales/'.$again->json('data.id').'/review', ['decision' => 'approved', 'note' => 'Re-approved after reversal',
        'physically_verified_line_ids' => SecondarySalesStatement::findOrFail($again->json('data.id'))->lines()->where('transaction_type', 'return')->pluck('id')->all()])->assertSuccessful();
    expect(ledgerNet($this))->toBe(90000);
});

it('rejects a correction without touching the approved statement and rejects the corrected upload', function () {
    $original = approvedStatement($this);
    $corrected = $this->rows;
    $corrected[0][6] = '900.00';
    $response = $this->post('/api/v1/secondary-sales/'.$original->id.'/corrections', ['kind' => 'replacement', 'reason' => 'Value typed incorrectly', 'reviewer_id' => $this->rsm->id,
        'file' => UploadedFile::fake()->createWithContent('corrected.csv', correctionCsv($corrected))], ['Accept' => 'application/json'])->assertCreated();
    Sanctum::actingAs($this->rsm);
    $this->postJson('/api/v1/secondary-sales/corrections/'.$response->json('data.id').'/review', ['decision' => 'rejected', 'note' => 'Stockist confirms the original invoice value'])->assertSuccessful()->assertJsonPath('data.status', 'rejected');
    expect($original->fresh()->status)->toBe('approved')->and(SecondarySalesStatement::findOrFail($response->json('data.replacement_statement_id'))->status)->toBe('rejected')
        ->and(ledgerNet($this))->toBe(90000)->and(DB::table('secondary_sales_groups')->count())->toBe(2);
    Sanctum::actingAs($this->rep);
    $this->postJson('/api/v1/secondary-sales/'.$original->id.'/corrections', ['kind' => 'reversal', 'reason' => 'New request after rejection', 'reviewer_id' => $this->rsm->id])->assertCreated();
});

it('renders the corrections queue in the ERP for requester and reviewer', function () {
    $original = approvedStatement($this);
    $this->postJson('/api/v1/secondary-sales/'.$original->id.'/corrections', ['kind' => 'reversal', 'reason' => 'Rendered in the ERP', 'reviewer_id' => $this->rsm->id])->assertCreated();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    foreach ([$this->rep, $this->rsm] as $user) {
        $this->actingAs($user);
        Livewire::test(ManageSecondarySalesCorrections::class)->assertSuccessful()->assertCanSeeTableRecords(SecondarySalesCorrection::query()->get());
    }
    $this->actingAs($this->other);
    Livewire::test(ManageSecondarySalesCorrections::class)->assertSuccessful()->assertCanNotSeeTableRecords(SecondarySalesCorrection::query()->get());
});
