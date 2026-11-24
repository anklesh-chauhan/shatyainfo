<?php

use App\Models\ExpenseType;
use App\Models\Media;
use App\Models\Organization;
use App\Models\SalesDcr;
use App\Models\SalesDcrExpense;
use App\Models\VisitPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('creates a manual expense only once when a queued upload is retried', function () {
    $rep = fieldReportingUser('ExpenseRep');
    Sanctum::actingAs($rep);
    $id = $this->getJson('/api/v1/dcrs/today')->assertSuccessful()->json('data.id');
    $type = ExpenseType::query()->create(['name' => 'Meals', 'code' => 'MEAL', 'is_active' => true]);
    $payload = ['expense_type_id' => $type->id, 'amount' => 125, 'remarks' => 'Field lunch'];
    $headers = ['Idempotency-Key' => (string) Str::uuid()];
    $this->postJson('/api/v1/dcrs/'.$id.'/expenses', $payload, $headers)->assertSuccessful();
    $this->postJson('/api/v1/dcrs/'.$id.'/expenses', $payload, $headers)->assertSuccessful()->assertHeader('Idempotency-Replayed', 'true');
    expect(SalesDcrExpense::query()->where('sales_dcr_id', $id)->count())->toBe(1);

    $this->getJson('/api/v1/dcr-expenses')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.sales_dcr_id', $id)->assertJsonPath('data.0.expense_type.name', 'Meals');
    Sanctum::actingAs(fieldReportingUser('Other Expense Rep'));
    $this->getJson('/api/v1/dcr-expenses')->assertOk()->assertJsonCount(0, 'data');
    Sanctum::actingAs($rep);

    Organization::query()->create(['name' => 'Expenses Disabled', 'metadata' => ['company_setup' => ['organization_saved' => true]]]);
    $this->postJson('/api/v1/dcrs/'.$id.'/expenses', $payload, $headers)->assertForbidden();
    $this->getJson('/api/v1/dcr-expenses')->assertForbidden();
    $this->getJson('/api/v1/dcr-expenses?register=1')->assertForbidden();
    $this->getJson('/api/v1/dcr-expenses/options?kind=reports')->assertForbidden();
});

it('rejects invalid expense amounts', function () {
    Sanctum::actingAs(fieldReportingUser('InvalidExpenseRep'));
    $id = $this->getJson('/api/v1/dcrs/today')->assertSuccessful()->json('data.id');
    $type = ExpenseType::query()->create(['name' => 'Meals', 'code' => 'MEAL', 'is_active' => true]);
    $this->postJson('/api/v1/dcrs/'.$id.'/expenses', ['expense_type_id' => $type->id, 'amount' => -1])
        ->assertUnprocessable()->assertJsonValidationErrors('amount');
});

it('creates once and moves an eligible expense while updating both reports and rejecting stale edits', function () {
    $rep = fieldReportingUser('Expense Register Rep');
    VisitPreference::current()->forceFill(['allow_backdated_dcr' => true, 'dcr_backdate_days' => 3])->save();
    Sanctum::actingAs($rep);
    $source = $this->getJson('/api/v1/dcrs/today')->assertCreated()->json('data.id');
    $target = SalesDcr::query()->forceCreate([
        'user_id' => $rep->id, 'dcr_date' => today()->subDay(), 'approval_status' => 'draft',
    ]);
    $type = ExpenseType::query()->create(['name' => 'Register Meals', 'code' => 'REGM', 'is_active' => true]);
    $url = '/api/v1/dcr-expenses';
    $payload = ['scope' => 'personal', 'sales_dcr_id' => $source, 'expense_type_id' => $type->id, 'amount' => '125.50'];
    $key = ['Idempotency-Key' => (string) Str::uuid()];
    $created = $this->postJson($url, $payload, $key)->assertCreated()->assertJsonPath('data.can_move', true);
    $this->postJson($url, $payload, $key)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
    $id = $created->json('data.id');
    $this->getJson($url.'?register=1&origin=manual&search=Meals')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $id)->assertJsonPath('meta.can_create', true);
    $update = [...$payload, 'sales_dcr_id' => $target->id, 'amount' => '90.25', 'revision' => $created->json('data.revision')];
    $moved = $this->patchJson($url.'/'.$id, $update)->assertOk()->assertJsonPath('data.sales_dcr_id', $target->id);
    expect((float) SalesDcr::query()->findOrFail($source)->total_expense)->toBe(0.0)
        ->and((float) $target->refresh()->total_expense)->toBe(90.25);
    $this->patchJson($url.'/'.$id, $update)->assertConflict();
    $this->patchJson('/api/v1/dcrs/'.$source.'/expenses/'.$id, ['amount' => 10], $key)->assertNotFound();

    SalesDcr::query()->whereKey($target->id)->update(['approval_status' => 'approved']);
    $this->getJson($url.'/options?kind=reports')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $source);
    $this->patchJson($url.'/'.$id, [...$update, 'revision' => $moved->json('data.revision')])->assertUnprocessable();
});

it('requires visibility of both the team expense and its parent and keeps receipt downloads owner scoped', function () {
    $manager = fieldReportingUser('Expense Register Manager');
    $rep = fieldReportingUser('Expense Register Owner');
    $visible = SalesDcr::query()->forceCreate(['user_id' => $rep->id, 'dcr_date' => today(), 'approval_status' => 'draft', 'created_by' => $manager->id]);
    $hidden = SalesDcr::query()->forceCreate(['user_id' => $rep->id, 'dcr_date' => today()->subDay(), 'approval_status' => 'draft', 'created_by' => $rep->id]);
    $type = ExpenseType::query()->create(['name' => 'Travel Meals', 'code' => 'TVLM', 'is_active' => true]);
    $base = ['expense_type_id' => $type->id, 'amount' => 30, 'is_auto_calculated' => false];
    $listed = $visible->expenses()->forceCreate([...$base, 'created_by' => $manager->id]);
    $hiddenChild = $visible->expenses()->forceCreate([...$base, 'created_by' => $rep->id]);
    $hiddenParent = $hidden->expenses()->forceCreate([...$base, 'created_by' => $manager->id]);
    $receipt = $listed->receipts()->create(['disk' => 'local', 'path' => 'test/receipt.png', 'original_name' => 'receipt.png', 'mime_type' => 'image/png', 'size' => 10]);

    Sanctum::actingAs($manager);
    $url = '/api/v1/dcr-expenses';
    $this->getJson($url.'?register=1&scope=team')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $listed->id);
    $detail = $this->getJson($url.'/'.$listed->id.'?scope=team')->assertOk()->assertJsonMissingPath('data.receipts')
        ->assertJsonPath('data.can_move', false);
    $this->getJson($url.'/'.$hiddenChild->id.'?scope=team')->assertNotFound();
    $this->getJson($url.'/'.$hiddenParent->id.'?scope=team')->assertNotFound();
    $this->getJson('/api/v1/dcrs/'.$visible->id.'/expenses/'.$listed->id.'/receipts/'.$receipt->id)->assertNotFound();
    $this->patchJson($url.'/'.$listed->id, [
        'expense_type_id' => $type->id, 'amount' => 30, 'scope' => 'team', 'sales_dcr_id' => $hidden->id, 'revision' => $detail->json('data.revision'),
    ])->assertNotFound();
});

it('keeps lifecycle batches atomic and preserves offline history when recalculating and clearing trash', function () {
    Sanctum::actingAs(grantPagePermissions(fieldReportingUser('Expense Lifecycle Rep'), 'Restore:SalesDcrExpense', 'ForceDelete:SalesDcrExpense'));
    $id = $this->getJson('/api/v1/dcrs/today')->assertCreated()->json('data.id');
    $report = SalesDcr::query()->findOrFail($id);
    $type = ExpenseType::query()->create(['name' => 'Lifecycle Meals', 'code' => 'LCM', 'is_active' => true]);
    $base = ['expense_type_id' => $type->id, 'amount' => 25];
    $manual = $report->expenses()->create([...$base, 'is_auto_calculated' => false]);
    $offline = $report->expenses()->create([...$base, 'is_auto_calculated' => false, 'meta' => ['operation_id' => (string) Str::uuid()]]);
    $calculated = $report->expenses()->create([...$base, 'is_auto_calculated' => true]);
    $url = '/api/v1/dcr-expenses';
    $apply = fn (string $action, array $ids) => $this->postJson($url.'/lifecycle', ['scope' => 'personal', 'action' => $action, 'ids' => $ids]);
    $apply('delete', [$manual->id, $calculated->id])->assertUnprocessable();
    expect($manual->fresh()->trashed())->toBeFalse();
    $apply('delete', [$manual->id, $offline->id])->assertOk()->assertJsonPath('data.updated', 2);
    $this->getJson($url.'?register=1&trash=only')->assertOk()->assertJsonCount(2, 'data');
    $apply('restore', [$manual->id])->assertOk();
    $apply('forceDelete', [$offline->id])->assertUnprocessable();
    $apply('delete', [$manual->id])->assertOk();
    $apply('forceDelete', [$manual->id])->assertOk();
    $apply('restore', [$offline->id])->assertOk();
    $this->postJson($url.'/recalculate', ['scope' => 'personal', 'sales_dcr_id' => $id])->assertOk();
    expect($report->expenses()->pluck('id')->all())->toBe([$offline->id])
        ->and((float) $report->refresh()->total_expense)->toBe(25.0);
});

describe('private expense receipts', function () {
    beforeEach(function () {
        Storage::fake('local');
        $this->rep = fieldReportingUser('ReceiptRep');
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
        $this->getJson('/api/v1/dcr-expenses/'.$expense->id)->assertOk()
            ->assertJsonPath('data.receipts.0.id', $media->id)->assertJsonMissingPath('data.receipts.0.path')
            ->assertJsonPath('data.can_move', false);
        $this->get('/api/v1/dcrs/'.$this->dcrId.'/expenses/'.$this->expenseId.'/receipts/'.$media->id)
            ->assertSuccessful()->assertHeader('X-Content-Type-Options', 'nosniff');
    });

    it('rejects non-image uploads without storing media', function () {
        $this->postJson($this->receiptUrl, ['receipt' => UploadedFile::fake()->createWithContent('receipt.txt', 'not an image')])
            ->assertUnprocessable()->assertJsonValidationErrors('receipt');
        expect(Media::query()->count())->toBe(0);
    });

    it('hides receipts and rejects upload by another employee', function () {
        Sanctum::actingAs(fieldReportingUser('OtherReceiptRep'));
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
