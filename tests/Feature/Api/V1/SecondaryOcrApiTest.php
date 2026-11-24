<?php

use App\Filament\Resources\SecondaryOcrSources\Pages\ManageSecondaryOcrSources;
use App\Jobs\ExtractSecondaryStatement;
use App\Models\SecondaryOcrSource;
use App\Models\SkuUnitConversion;
use App\Services\Field\SecondaryOcrService;
use App\Services\Field\SecondarySalesService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    config(['services.secondary_ocr.tesseract_binary' => null]);
    $this->rep = reportingUser('OCRUploader');
    $this->rsm = reportingUser('OCRReviewer');
    foreach (['Create:SecondarySales', 'Review:SecondarySales', 'View:AccountMaster', 'AccessAllRecords'] as $name) {
        Permission::findOrCreate($name, 'web');
    }
    $this->rep->givePermissionTo(['Create:SecondarySales', 'View:AccountMaster', 'AccessAllRecords']);
    $this->rsm->givePermissionTo('Review:SecondarySales');
    $territory = reportingTerritory('OCR Territory');
    $stockist = reportingDoctor($this->rep, 'OCR Stockist');
    $stockist->update(['territory_id' => $territory->id, 'accept_secondary_statements' => true]);
    $this->data = ['stockist_id' => $stockist->id, 'territory_id' => $territory->id, 'reviewer_id' => $this->rsm->id, 'period' => today()->format('Y-m')];
    Sanctum::actingAs($this->rep);
});

it('retains scans privately without an engine and maps reviewed OCR text through the shared import pipeline', function () {
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
    $upload = fn () => $this->post('/api/v1/secondary-ocr', [...$this->data, 'file' => UploadedFile::fake()->createWithContent('scan.png', $png)], ['Accept' => 'application/json']);
    $id = $upload()->assertCreated()->assertJsonPath('data.status', 'awaiting_provider')->json('data.id');
    $upload()->assertCreated()->assertJsonPath('data.id', $id);
    $source = SecondaryOcrSource::findOrFail($id);
    Storage::disk('local')->assertExists($source->source_path);
    $root = '/api/v1/secondary-ocr';
    $path = $root.'/'.$id;
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertOk()
        ->assertJsonFragment(['key' => 'g05-03', 'screen_key' => 'ocr-sources']);
    $this->getJson($root.'?register=1')->assertOk()->assertJsonPath('register.can_upload', true)
        ->assertJsonPath('data.0.stockist.name', 'OCR Stockist')->assertJsonMissingPath('data.0.extracted_text');
    $this->getJson($path.'?register=1')->assertOk()->assertJsonPath('data.can_retry', true)
        ->assertJsonPath('data.can_map', true)->assertJsonMissingPath('data.source_path')
        ->assertJsonMissingPath('data.extraction_token')->assertJsonMissingPath('data.tokens');
    $this->postJson($path.'/retry')->assertUnprocessable();
    expect($source->fresh()->status)->toBe('awaiting_provider');

    Sanctum::actingAs($this->rsm);
    $this->getJson($root.'?register=1')->assertOk()->assertJsonPath('register.can_upload', false);
    $this->getJson($path.'?register=1')->assertOk()->assertJsonPath('data.can_retry', false)->assertJsonPath('data.can_map', false);
    $download = $this->get($path.'/file')->assertOk()->assertDownload();
    expect($download->streamedContent())->toBe($png)->and($download->headers->get('cache-control'))->toContain('private', 'no-store');
    $this->postJson($path.'/retry')->assertForbidden();
    $this->rsm->revokePermissionTo('Review:SecondarySales');
    $this->getJson($path.'?register=1')->assertForbidden();
    $this->getJson($path.'/file')->assertForbidden();

    $outside = reportingUser('OtherOCRUser');
    $outside->givePermissionTo('Review:SecondarySales');
    Sanctum::actingAs($outside);
    $this->getJson($root.'?register=1')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($path.'?register=1')->assertNotFound();
    $this->getJson($path.'/file')->assertNotFound();
    $this->postJson($path.'/retry')->assertNotFound();
    $this->rsm->givePermissionTo('Review:SecondarySales');
    Sanctum::actingAs($this->rep);
    Storage::disk('local')->put('fake-tesseract.exe', 'test executable placeholder');
    config(['services.secondary_ocr.tesseract_binary' => Storage::disk('local')->path('fake-tesseract.exe')]);
    Process::fake(['*' => Process::result(output: "level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext\n5\t1\t1\t1\t1\t1\t10\t10\t30\t10\t80\tInvoice\n5\t1\t1\t1\t1\t2\t40\t10\t30\t10\t100\t123\n")]);
    app(SecondaryOcrService::class)->extract($id);
    expect($source->fresh()->status)->toBe('needs_mapping')->and($source->fresh()->extracted_text)->toBe('Invoice 123')
        ->and((float) $source->fresh()->mean_confidence)->toBe(90.0)->and($source->fresh()->tokens)->toHaveCount(2);
    $item = reportingSampleItem('OCR-SKU', 'OCR Product');
    SkuUnitConversion::query()->create(['item_master_id' => $item->id, 'unit' => 'box', 'base_units' => 100]);
    $csv = implode(',', SecondarySalesService::HEADERS)."\nINV-123,".today()->toDateString().',B1,'.$item->item_code.",box,2,100,0,sale,\n";
    $this->post('/api/v1/secondary-sales', [...$this->data, 'ocr_source_id' => $id, 'ocr_mapping_note' => 'Checked all quantities and identifiers against the original scan',
        'file' => UploadedFile::fake()->createWithContent('mapped.csv', $csv)], ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonPath('data.status', 'pending_review');
    expect($source->fresh()->status)->toBe('converted')->and($source->fresh()->secondary_sales_statement_id)->not->toBeNull();
    $this->getJson($path.'?register=1')->assertOk()->assertJsonPath('data.can_map', false)->assertJsonPath('data.can_retry', false)
        ->assertJsonPath('data.statement.status', 'pending_review')->assertJsonPath('data.extracted_text', 'Invoice 123')
        ->assertJsonPath('data.mapping_note', 'Checked all quantities and identifiers against the original scan');
    $this->postJson($path.'/retry')->assertConflict();
    app(SecondaryOcrService::class)->extract($id);
    Process::assertRanTimes(fn () => true, 1);
});

it('renders scan intake and text review without granting approval or delete access', function () {
    $this->rep->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($this->rep);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(ManageSecondaryOcrSources::class)->assertSuccessful();
});

it('recovers abandoned OCR workers without allowing obsolete attempts to overwrite a newer extraction', function () {
    Queue::fake();
    $service = app(SecondaryOcrService::class);
    $source = $service->capture($this->rep, UploadedFile::fake()->image('recovery.png'), $this->data);
    Storage::disk('local')->put('fake-tesseract.exe', 'test executable placeholder');
    config(['services.secondary_ocr.tesseract_binary' => Storage::disk('local')->path('fake-tesseract.exe')]);
    $oldToken = $source->extraction_token;
    $source->update(['status' => 'extracting', 'extraction_started_at' => now()]);
    $path = '/api/v1/secondary-ocr/'.$source->id;
    $this->getJson($path.'?register=1')->assertOk()->assertJsonPath('data.can_retry', false);
    $this->postJson($path.'/retry')->assertConflict();
    $this->travel(16)->minutes();
    $this->getJson($path.'?register=1')->assertOk()->assertJsonPath('data.can_retry', true);
    $this->postJson($path.'/retry')->assertOk()->assertJsonPath('data.status', 'queued')->assertJsonPath('data.can_retry', false);
    $this->postJson($path.'/retry')->assertConflict();
    $nextToken = $source->fresh()->extraction_token;
    expect($nextToken)->not->toBe($oldToken)->and($source->fresh()->status)->toBe('queued');
    Queue::assertPushed(ExtractSecondaryStatement::class, fn ($job) => $job->sourceId === $source->id && $job->extractionToken === $nextToken);
    Process::fake();
    $service->extract($source->id, $oldToken);
    (new ExtractSecondaryStatement($source->id, $oldToken))->failed(new RuntimeException('Old worker stopped'));
    Process::assertNothingRan();
    expect($source->fresh()->status)->toBe('queued');

    $replacement = (string) Str::uuid();
    Process::fake(function () use ($source, $replacement) {
        $source->update(['status' => 'queued', 'extraction_token' => $replacement]);

        return Process::result(output: "5\t1\t1\t1\t1\t1\t10\t10\t30\t10\t90\tObsolete\n");
    });
    $service->extract($source->id, $nextToken);
    expect($source->fresh()->status)->toBe('queued')->and($source->fresh()->extracted_text)->toBeNull();
    $job = new ExtractSecondaryStatement($source->id, $replacement);
    expect($job->failOnTimeout)->toBeTrue();
    $job->failed(new RuntimeException('Current worker stopped'));
    expect($source->fresh()->status)->toBe('failed')->and($source->fresh()->error)->toContain('retained');
    Storage::disk('local')->assertExists($source->source_path);
    $service->retry($this->rep, $source->fresh());
    Process::fake(['*' => Process::result(output: "5\t1\t1\t1\t1\t1\t10\t10\t30\t10\t95\tRecovered\n")]);
    $service->extract($source->id, $source->fresh()->extraction_token);
    expect($source->fresh()->status)->toBe('needs_mapping')->and($source->fresh()->extracted_text)->toBe('Recovered');
    $job->failed(new RuntimeException('Late failure'));
    expect($source->fresh()->status)->toBe('needs_mapping');
});
