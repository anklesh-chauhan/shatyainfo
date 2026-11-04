<?php

use App\Filament\Resources\FieldSyncRecoveries\Pages\ManageFieldSyncRecoveries;
use App\Models\ExpenseType;
use App\Models\FieldDcrEntry;
use App\Models\FieldSyncReceipt;
use App\Models\FieldSyncRecovery;
use App\Models\SalesDcr;
use App\Models\SalesDcrExpense;
use App\Models\Visit;
use App\Models\VisitGeoAssessment;
use App\Models\VisitPreference;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->rep = reportingUser('RecoveryRep');
    $this->manager = reportingUser('RecoveryManager');
    Permission::findOrCreate('Review:FieldSyncRecovery', 'web');
    $this->manager->givePermissionTo('Review:FieldSyncRecovery');
    VisitPreference::current()->update(['allow_backdated_dcr' => false, 'enforce_geo_fencing' => false]);
    $this->old = now()->subDays(10)->setTime(12, 0);
    $this->event = ['event_id' => (string) Str::uuid(), 'date' => $this->old->toDateString(), 'category' => 'training',
        'remarks' => 'Product training captured while disconnected', 'captured_at' => $this->old->utc()->toIso8601String()];
    Sanctum::actingAs($this->rep);
});

function requestLateRecovery($test, array $event, string $path = '/api/v1/field-dcr-events'): FieldSyncRecovery
{
    $test->postJson($path, $event, ['Idempotency-Key' => $event['event_id']])->assertUnprocessable();
    $test->postJson('/api/v1/field-sync-recoveries/'.$event['event_id'].'/request', ['reviewer_id' => $test->manager->id, 'reason' => 'Device remained disconnected during rural field work'])->assertSuccessful();

    return FieldSyncRecovery::query()->where('operation_id', $event['event_id'])->sole();
}

function approveLateRecovery($test, FieldSyncRecovery $record): void
{
    Sanctum::actingAs($test->manager);
    $test->patchJson('/api/v1/field-sync-recoveries/'.$record->id.'/review', ['decision' => 'approved', 'note' => 'Original evidence checked; permit the exact late upload'])->assertSuccessful();
    Sanctum::actingAs($test->rep);
}

it('recovers the exact late event once without opening the date for other edits', function () {
    $record = requestLateRecovery($this, $this->event);
    expect(FieldDcrEntry::query()->count())->toBe(0)->and(FieldSyncReceipt::query()->count())->toBe(0)
        ->and($record->snapshot['request']['captured_at'])->toBe($this->event['captured_at']);
    $this->getJson('/api/v1/field-sync-recoveries')->assertSuccessful()->assertJsonPath('data.0.status', 'pending_review');
    $this->patchJson('/api/v1/field-sync-recoveries/'.$record->id.'/review', ['decision' => 'approved', 'note' => 'Own approval'])->assertForbidden();
    approveLateRecovery($this, $record);
    $path = '/api/v1/field-dcr-events';
    $headers = ['Idempotency-Key' => $this->event['event_id']];
    $this->postJson($path, [...$this->event, 'remarks' => 'Changed after approval'], $headers)->assertConflict();
    $first = $this->postJson($path, $this->event, $headers)->assertSuccessful()->assertJsonPath('data.reportable', false);
    $id = $first->json('data.id');
    $this->getJson('/api/v1/dcrs/'.$id)->assertSuccessful()->assertJsonPath('data.recovery_submission_allowed', true);
    $this->postJson($path, $this->event, $headers)->assertSuccessful()->assertHeader('Idempotency-Replayed', 'true');
    expect(FieldDcrEntry::query()->count())->toBe(1)->and($record->fresh()->status)->toBe('recovered');
    $new = [...$this->event, 'event_id' => (string) Str::uuid()];
    $this->postJson($path, $new, ['Idempotency-Key' => $new['event_id']])->assertUnprocessable();
    $this->postJson('/api/v1/dcrs/'.$id.'/submit')->assertSuccessful()->assertJsonPath('data.approval_status', 'approved');
    expect(FieldDcrEntry::query()->sole()->captured_at->equalTo($this->old))->toBeTrue();
});

it('scopes review evidence, expires approvals, retains renewal history and checks current reviewer authority', function () {
    $record = requestLateRecovery($this, $this->event);
    Sanctum::actingAs(reportingUser('UnrelatedRecoveryUser'));
    $this->getJson('/api/v1/field-sync-recoveries')->assertSuccessful()->assertJsonCount(0, 'data');
    $this->postJson('/api/v1/field-sync-recoveries/'.$record->operation_id.'/request', ['reviewer_id' => $this->manager->id, 'reason' => 'Try another representative record'])->assertNotFound();
    approveLateRecovery($this, $record);
    $this->manager->revokePermissionTo('Review:FieldSyncRecovery');
    $this->postJson('/api/v1/field-dcr-events', $this->event, ['Idempotency-Key' => $record->operation_id])->assertUnprocessable();
    $this->manager->givePermissionTo('Review:FieldSyncRecovery');
    $this->travel(8)->days();
    $this->postJson('/api/v1/field-dcr-events', $this->event, ['Idempotency-Key' => $record->operation_id])->assertUnprocessable();
    $this->postJson('/api/v1/field-sync-recoveries/'.$record->operation_id.'/request', ['reviewer_id' => $this->manager->id, 'reason' => 'Renew exception after continued outage'])->assertSuccessful();
    expect($record->fresh()->review_history)->toHaveCount(1);
    Sanctum::actingAs($this->manager);
    $this->patchJson('/api/v1/field-sync-recoveries/'.$record->id.'/review', ['decision' => 'rejected', 'note' => 'Insufficient supporting evidence'])->assertSuccessful();
    Sanctum::actingAs($this->rep);
    $this->postJson('/api/v1/field-dcr-events', $this->event, ['Idempotency-Key' => $record->operation_id])->assertUnprocessable();
    expect(FieldDcrEntry::query()->count())->toBe(0);
});

it('does not bypass locked DCRs or allow self-selected and future capture exceptions', function () {
    $record = requestLateRecovery($this, $this->event);
    SalesDcr::query()->create(['user_id' => $this->rep->id, 'dcr_date' => $record->report_date, 'approval_status' => 'approved']);
    Sanctum::actingAs($this->manager);
    $this->patchJson('/api/v1/field-sync-recoveries/'.$record->id.'/review', ['decision' => 'approved', 'note' => 'Try to reopen an approved DCR'])->assertUnprocessable();
    Sanctum::actingAs($this->rep);
    $future = [...$this->event, 'event_id' => (string) Str::uuid(), 'date' => today()->toDateString(), 'captured_at' => now()->addHour()->utc()->toIso8601String()];
    $this->postJson('/api/v1/field-dcr-events', $future, ['Idempotency-Key' => $future['event_id']])->assertUnprocessable();
    $this->postJson('/api/v1/field-sync-recoveries/'.$future['event_id'].'/request', ['reviewer_id' => $this->manager->id, 'reason' => 'Try a future timestamp'])->assertUnprocessable();
    expect(FieldDcrEntry::query()->count())->toBe(0);
});

it('recovers a late expense once and retains its capture metadata', function () {
    $type = ExpenseType::query()->create(['name' => 'Recovery conveyance', 'code' => 'RCV', 'is_active' => true]);
    $event = ['event_id' => (string) Str::uuid(), 'date' => $this->old->toDateString(), 'captured_at' => $this->event['captured_at'],
        'expense_type_id' => $type->id, 'amount' => 175, 'remarks' => 'Original rural travel fare'];
    $record = requestLateRecovery($this, $event, '/api/v1/field-expense-events');
    approveLateRecovery($this, $record);
    $this->postJson('/api/v1/field-expense-events', $event, ['Idempotency-Key' => $event['event_id']])->assertSuccessful()->assertJsonPath('data.amount', 175);
    $this->postJson('/api/v1/field-expense-events', $event, ['Idempotency-Key' => $event['event_id']])->assertSuccessful()->assertHeader('Idempotency-Replayed', 'true');
    expect(SalesDcrExpense::query()->count())->toBe(1)->and(SalesDcrExpense::query()->sole()->meta['captured_at'])->toBe($event['captured_at']);
});

it('renders the scoped ERP recovery queue without editable or delete actions', function () {
    requestLateRecovery($this, $this->event);
    $this->actingAs($this->rep);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(ManageFieldSyncRecoveries::class)->assertSuccessful();
});

it('rechecks geo-fencing after recovery and still requires ASM clearance before DCR closure', function () {
    $doctor = reportingDoctor($this->rep, 'Late clinic');
    $doctor->update(['geo_latitude' => 19, 'geo_longitude' => 72, 'geo_radius_meters' => 100]);
    $territory = reportingTerritory('Recovery territory');
    $patch = reportingPatch($territory, 'Recovery patch');
    $dcr = SalesDcr::query()->create(['user_id' => $this->rep->id, 'dcr_date' => $this->event['date'], 'approval_status' => 'draft']);
    $visit = Visit::query()->create(['document_number' => 'RECOVERY-GEO', 'employee_id' => $this->rep->id, 'visit_date' => $this->event['date'],
        'visit_status' => 'draft', 'sales_dcr_id' => $dcr->id, 'territory_id' => $territory->id, 'patch_id' => $patch->id]);
    $visit->visitables()->create(['visitable_type' => $doctor::class, 'visitable_id' => $doctor->id]);
    VisitPreference::current()->update(['enforce_geo_fencing' => true, 'geo_asm_user_id' => $this->manager->id, 'enable_check_in' => true,
        'require_check_in_image' => false, 'require_general_visit_image' => false, 'field_rules' => []]);
    $event = ['event_id' => (string) Str::uuid(), 'action' => 'check_in', 'target' => ['visit_id' => $visit->id],
        'captured_at' => $this->event['captured_at'], 'payload' => ['latitude' => 19.01, 'longitude' => 72, 'accuracy_meters' => 10]];
    $record = requestLateRecovery($this, $event, '/api/v1/field-visit-events');
    approveLateRecovery($this, $record);
    $this->postJson('/api/v1/field-visit-events', $event, ['Idempotency-Key' => $event['event_id']])->assertSuccessful();
    expect(VisitGeoAssessment::query()->sole()->result)->toBe('outside');
    $this->postJson('/api/v1/dcrs/'.$dcr->id.'/submit')->assertUnprocessable()->assertJsonValidationErrors('geo_fence');
    expect($dcr->fresh()->approval_status)->toBe('draft');
});

it('does not turn a reviewed late sample into a distribution-version bypass', function () {
    $territory = reportingTerritory('Recovery sample territory');
    $patch = reportingPatch($territory, 'Recovery sample patch');
    $dcr = SalesDcr::query()->create(['user_id' => $this->rep->id, 'dcr_date' => $this->event['date'], 'approval_status' => 'draft']);
    $visit = Visit::query()->create(['document_number' => 'RECOVERY-SAMPLES', 'employee_id' => $this->rep->id, 'visit_date' => $this->event['date'],
        'visit_status' => 'started', 'start_time' => $this->old, 'sales_dcr_id' => $dcr->id, 'territory_id' => $territory->id, 'patch_id' => $patch->id]);
    $item = reportingSampleItem('LATE-SAMPLE', 'Late sample');
    $event = ['event_id' => (string) Str::uuid(), 'action' => 'samples', 'target' => ['visit_id' => $visit->id], 'captured_at' => $this->event['captured_at'],
        'payload' => ['expected_version' => str_repeat('a', 64), 'items' => [['item_master_id' => $item->id, 'quantity' => 1]]]];
    $record = requestLateRecovery($this, $event, '/api/v1/field-visit-events');
    approveLateRecovery($this, $record);
    $this->postJson('/api/v1/field-visit-events', $event, ['Idempotency-Key' => $event['event_id']])->assertConflict();
    expect($visit->sgipDistribution()->count())->toBe(0)->and($record->fresh()->status)->toBe('approved');
});

it('binds late receipt recovery to the original image bytes and posts the attachment only once', function () {
    Storage::fake('local');
    $type = ExpenseType::query()->create(['name' => 'Late receipt type', 'code' => 'LRT', 'is_active' => true]);
    $dcr = SalesDcr::query()->create(['user_id' => $this->rep->id, 'dcr_date' => $this->event['date'], 'approval_status' => 'draft']);
    $expense = SalesDcrExpense::query()->create(['sales_dcr_id' => $dcr->id, 'expense_type_id' => $type->id, 'amount' => 125, 'is_auto_calculated' => false]);
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
    $operation = (string) Str::uuid();
    $headers = ['Idempotency-Key' => $operation, 'Accept' => 'application/json'];
    $path = '/api/v1/dcrs/'.$dcr->id.'/expenses/'.$expense->id.'/receipt';
    $original = fn () => UploadedFile::fake()->createWithContent('receipt.png', $png);
    $this->post($path, ['receipt' => $original()], $headers)->assertUnprocessable();
    $this->postJson('/api/v1/field-sync-recoveries/'.$operation.'/request', ['reviewer_id' => $this->manager->id, 'reason' => 'Receipt upload delayed by rural network outage'])->assertSuccessful();
    $record = FieldSyncRecovery::query()->where('operation_id', $operation)->sole();
    expect($record->snapshot['file_sha256']['receipt'])->toBe(hash('sha256', $png));
    approveLateRecovery($this, $record);
    $this->post($path, ['receipt' => UploadedFile::fake()->image('different.png', 2, 2)], $headers)->assertConflict();
    $this->post($path, ['receipt' => $original()], $headers)->assertSuccessful();
    $this->post($path, ['receipt' => $original()], $headers)->assertSuccessful()->assertHeader('Idempotency-Replayed', 'true');
    expect($expense->receipts()->count())->toBe(1)->and($record->fresh()->status)->toBe('recovered');
});

it('keeps invalid expense dates out of reviewable reporting-window exceptions', function () {
    $type = ExpenseType::query()->create(['name' => 'Invalid date type', 'code' => 'IDT', 'is_active' => true]);
    $event = ['event_id' => (string) Str::uuid(), 'date' => '2026-99-99', 'captured_at' => $this->event['captured_at'], 'expense_type_id' => $type->id, 'amount' => 20];
    $this->postJson('/api/v1/field-expense-events', $event, ['Idempotency-Key' => $event['event_id']])->assertUnprocessable()->assertJsonValidationErrors('date');
    expect(FieldSyncRecovery::query()->where('operation_id', $event['event_id'])->sole()->report_date)->toBeNull();
    $this->postJson('/api/v1/field-sync-recoveries/'.$event['event_id'].'/request', ['reviewer_id' => $this->manager->id, 'reason' => 'Invalid dates cannot be approved'])->assertUnprocessable();
});

it('blocks known pending recovery work even while the ordinary reporting window is open', function () {
    VisitPreference::current()->update(['allow_backdated_dcr' => true, 'dcr_backdate_days' => 31]);
    $dcr = SalesDcr::query()->create(['user_id' => $this->rep->id, 'dcr_date' => $this->event['date'], 'approval_status' => 'draft']);
    FieldDcrEntry::query()->create(['event_id' => (string) Str::uuid(), 'user_id' => $this->rep->id, 'sales_dcr_id' => $dcr->id,
        'category' => 'meeting', 'remarks' => 'Previously synchronized work', 'captured_at' => $this->old, 'received_at' => now()]);
    $record = requestLateRecovery($this, $this->event);
    $this->getJson('/api/v1/dcrs/'.$dcr->id)->assertSuccessful()->assertJsonPath('data.reportable', true)->assertJsonPath('data.recovery_review_pending', true);
    $this->postJson('/api/v1/dcrs/'.$dcr->id.'/submit')->assertUnprocessable()->assertJsonValidationErrors('field_sync_recovery');
    approveLateRecovery($this, $record);
    $this->postJson('/api/v1/dcrs/'.$dcr->id.'/submit')->assertUnprocessable()->assertJsonValidationErrors('field_sync_recovery');
    $this->postJson('/api/v1/field-dcr-events', $this->event, ['Idempotency-Key' => $this->event['event_id']])->assertSuccessful();
    $this->postJson('/api/v1/dcrs/'.$dcr->id.'/submit')->assertSuccessful();
});

it('renews an expired recovered DCR closing window without applying its captured work again', function () {
    $record = requestLateRecovery($this, $this->event);
    approveLateRecovery($this, $record);
    $id = $this->postJson('/api/v1/field-dcr-events', $this->event, ['Idempotency-Key' => $this->event['event_id']])->assertSuccessful()->json('data.id');
    $this->travel(8)->days();
    $this->getJson('/api/v1/dcrs/'.$id)->assertSuccessful()->assertJsonPath('data.recovery_submission_allowed', false);
    $this->postJson('/api/v1/field-sync-recoveries/'.$record->operation_id.'/request', ['reviewer_id' => $this->manager->id, 'reason' => 'Records already recovered; request time to finish DCR review'])->assertSuccessful();
    approveLateRecovery($this, $record);
    expect($record->fresh()->status)->toBe('recovered')->and($record->fresh()->review_history)->toHaveCount(1);
    $this->postJson('/api/v1/dcrs/'.$id.'/submit')->assertSuccessful();
    expect(FieldDcrEntry::query()->count())->toBe(1);
});
