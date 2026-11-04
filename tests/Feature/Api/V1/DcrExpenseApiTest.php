<?php

use App\Models\ExpenseType;
use App\Models\Media;
use App\Models\SalesDcr;
use App\Models\SalesDcrExpense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('creates a manual expense only once when a queued upload is retried', function () {
    Sanctum::actingAs(reportingUser('ExpenseRep'));
    $id = $this->getJson('/api/v1/dcrs/today')->assertSuccessful()->json('data.id');
    $type = ExpenseType::query()->create(['name' => 'Meals', 'code' => 'MEAL', 'is_active' => true]);
    $payload = ['expense_type_id' => $type->id, 'amount' => 125, 'remarks' => 'Field lunch'];
    $headers = ['Idempotency-Key' => (string) Str::uuid()];
    $this->postJson('/api/v1/dcrs/'.$id.'/expenses', $payload, $headers)->assertSuccessful();
    $this->postJson('/api/v1/dcrs/'.$id.'/expenses', $payload, $headers)->assertSuccessful()->assertHeader('Idempotency-Replayed', 'true');
    expect(SalesDcrExpense::query()->where('sales_dcr_id', $id)->count())->toBe(1);
});

it('rejects invalid expense amounts', function () {
    Sanctum::actingAs(reportingUser('InvalidExpenseRep'));
    $id = $this->getJson('/api/v1/dcrs/today')->assertSuccessful()->json('data.id');
    $type = ExpenseType::query()->create(['name' => 'Meals', 'code' => 'MEAL', 'is_active' => true]);
    $this->postJson('/api/v1/dcrs/'.$id.'/expenses', ['expense_type_id' => $type->id, 'amount' => -1])
        ->assertUnprocessable()->assertJsonValidationErrors('amount');
});

describe('private expense receipts', function () {
    beforeEach(function () {
        Storage::fake('local');
        $this->rep = reportingUser('ReceiptRep');
        Sanctum::actingAs($this->rep);
        $this->dcrId = $this->getJson('/api/v1/dcrs/today')->assertSuccessful()->json('data.id');
        $type = ExpenseType::query()->create(['name' => 'Conveyance', 'code' => 'RCPT', 'is_active' => true]);
        $this->expenseId = $this->postJson('/api/v1/dcrs/'.$this->dcrId.'/expenses', [
            'expense_type_id' => $type->id, 'amount' => 120,
        ])->assertSuccessful()->json('data.id');
        $this->receiptUrl = '/api/v1/dcrs/'.$this->dcrId.'/expenses/'.$this->expenseId.'/receipt';
    });

    it('stores privately and replays uploads without duplicating receipts', function () {
        $key = ['Idempotency-Key' => (string) Str::uuid()];
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
        $makeReceipt = fn () => UploadedFile::fake()->createWithContent('receipt.png', $png);
        $first = $this->post($this->receiptUrl, ['receipt' => $makeReceipt()], $key)->assertSuccessful();
        $second = $this->post($this->receiptUrl, ['receipt' => $makeReceipt()], $key)
            ->assertSuccessful()->assertHeader('Idempotency-Replayed', 'true');
        expect($second->json())->toBe($first->json());
        $expense = SalesDcrExpense::query()->findOrFail($this->expenseId);
        expect($expense->receipts()->count())->toBe(1);
        $media = $expense->receipts()->firstOrFail();
        expect($media->disk)->toBe('local');
        Storage::disk('local')->assertExists($media->path);
        $first->assertJsonMissingPath('data.path');
        $this->get('/api/v1/dcrs/'.$this->dcrId.'/expenses/'.$this->expenseId.'/receipts/'.$media->id)
            ->assertSuccessful()->assertHeader('X-Content-Type-Options', 'nosniff');
    });

    it('rejects non-image uploads without storing media', function () {
        $this->postJson($this->receiptUrl, ['receipt' => UploadedFile::fake()->createWithContent('receipt.txt', 'not an image')])
            ->assertUnprocessable()->assertJsonValidationErrors('receipt');
        expect(Media::query()->count())->toBe(0);
    });

    it('hides receipts and rejects upload by another employee', function () {
        Sanctum::actingAs(reportingUser('OtherReceiptRep'));
        $this->postJson($this->receiptUrl, ['receipt' => UploadedFile::fake()->createWithContent('receipt.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='))])
            ->assertNotFound();
        $this->getJson('/api/v1/dcrs/'.$this->dcrId.'/expenses/'.$this->expenseId.'/receipts/1')->assertNotFound();
        expect(Media::query()->count())->toBe(0);
    });

    it('rejects receipts on locked DCRs', function () {
        SalesDcr::query()->whereKey($this->dcrId)->update(['approval_status' => 'approved']);
        $this->postJson($this->receiptUrl, ['receipt' => UploadedFile::fake()->createWithContent('receipt.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='))])
            ->assertUnprocessable();
        expect(Media::query()->count())->toBe(0);
    });
});
