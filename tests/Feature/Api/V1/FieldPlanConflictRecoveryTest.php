<?php

use App\Enums\FieldPlanningMode;
use App\Models\FieldSyncRecovery;
use App\Models\SalesDcr;
use App\Models\Visit;
use App\Models\VisitPreference;
use App\Services\Field\FieldPlanningService;
use App\Services\Field\FieldPlanningSettingsService;
use App\Services\Field\FieldSyncRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->rep = reportingUser('PlanRecoveryRep');
    $this->manager = reportingUser('PlanRecoveryManager');
    $this->unrelated = reportingUser('PlanRecoveryOther');
    foreach (['Create:Visit', 'Manage:FieldPlanningPreference', 'ViewAny:AccountMaster', 'View:AccountMaster', 'ViewOwn:AccountMaster', 'Review:FieldSyncRecovery'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $this->rep->givePermissionTo(['Create:Visit', 'Manage:FieldPlanningPreference', 'ViewAny:AccountMaster', 'View:AccountMaster', 'ViewOwn:AccountMaster']);
    $this->manager->givePermissionTo('Review:FieldSyncRecovery');
    $this->unrelated->givePermissionTo('Review:FieldSyncRecovery');
    Sanctum::actingAs($this->rep);
    $this->doctor = reportingDoctor($this->rep, 'Recovery Doctor');
    $this->settings = app(FieldPlanningSettingsService::class);
    $revision = $this->settings->saveProposal($this->rep, ['mode' => 'flexible', 'rules' => app(FieldPlanningService::class)->preset(FieldPlanningMode::Flexible), 'reason' => 'Plan recovery tests'], 0);
    $this->settings->activate($this->rep, $revision->id, 1);
    VisitPreference::current()->update(['require_gps' => false, 'enforce_geo_fencing' => false, 'field_rules' => [],
        'enable_check_in' => true, 'enable_check_out' => true, 'require_check_in_image' => false,
        'require_check_out_image' => false, 'require_general_visit_image' => false, 'enforce_minimum_duration' => false]);
    $this->proposal = ['client_plan_id' => (string) Str::uuid(), 'expected_version' => 0,
        'account_id' => $this->doctor->id, 'date' => today()->toDateString(), 'status' => 'active', 'notes' => 'Original'];
    $this->entry = $this->postJson('/api/v1/flexible-plans', $this->proposal)->assertSuccessful()->json('data');
    $this->getJson('/api/v1/dcrs/today')->assertSuccessful();
    $this->operation = (string) Str::uuid();
    $this->call = ['client_call_id' => (string) Str::uuid(), 'company_id' => $this->doctor->id,
        'date' => today()->toDateString(), 'captured_at' => now()->subMinutes(10)->toIso8601String(),
        'flexible_plan_entry_id' => $this->entry['id'], 'flexible_plan_version' => 1];
    $this->requestResolution = ['reviewer_id' => $this->manager->id, 'reason' => 'Verified actual doctor call captured before the plan changed',
        'resolution_mode' => 'accept_unplanned', 'confirmed_actual_visit' => true];
});

function failPlanOpening($test, string $status = 'active'): FieldSyncRecovery
{
    $test->postJson('/api/v1/flexible-plans', [...$test->proposal, 'expected_version' => 1, 'status' => $status, 'notes' => 'Changed'])->assertSuccessful();
    $test->postJson('/api/v1/visits/direct', $test->call, ['Idempotency-Key' => $test->operation])->assertConflict();

    return FieldSyncRecovery::query()->where('operation_id', $test->operation)->sole();
}

function requestPlanResolution($test): FieldSyncRecovery
{
    $test->postJson('/api/v1/field-sync-recoveries/'.$test->operation.'/request', $test->requestResolution)->assertSuccessful();

    return FieldSyncRecovery::query()->where('operation_id', $test->operation)->sole();
}

function approvePlanResolution($test, FieldSyncRecovery $record): void
{
    Sanctum::actingAs($test->manager);
    $test->patchJson('/api/v1/field-sync-recoveries/'.$record->id.'/review', ['decision' => 'approved',
        'note' => 'Actual field call verified; accept as unplanned', 'confirmed_actual_visit' => true,
        'review_token' => $record->fresh()->reviewToken()])->assertSuccessful();
    Sanctum::actingAs($test->rep);
}

it('reviews stale or cancelled captures and replays their original dependent chain once', function (string $status) {
    $failed = failPlanOpening($this, $status);
    expect(Arr::sortRecursive($failed->snapshot['request']))->toBe(Arr::sortRecursive($this->call))->and($failed->report_date->toDateString())->toBe($this->call['date']);
    $this->getJson('/api/v1/field-sync-recoveries')->assertJsonPath('data.0.can_request_plan_resolution', true);
    $record = requestPlanResolution($this);
    expect(Visit::query()->count())->toBe(0);
    $this->postJson('/api/v1/visits/direct', $this->call, ['Idempotency-Key' => $this->operation])->assertConflict();
    approvePlanResolution($this, $record);
    $this->postJson('/api/v1/visits/direct', [...$this->call, 'company_id' => 999999], ['Idempotency-Key' => $this->operation])->assertConflict();
    $opened = $this->postJson('/api/v1/visits/direct', $this->call, ['Idempotency-Key' => $this->operation])
        ->assertSuccessful()->assertJsonPath('data.visit_type', 'unplanned')->assertJsonPath('data.flexible_plan_entry_id', null)
        ->assertJsonPath('sync_resolution.kind', 'accept_unplanned');
    $id = $opened->json('data.id');
    $this->postJson('/api/v1/visits/direct', $this->call, ['Idempotency-Key' => $this->operation])->assertSuccessful()->assertHeader('Idempotency-Replayed', 'true');
    $target = Arr::except($this->call, ['captured_at']);
    $previous = null;
    foreach (['check_in', 'details', 'check_out'] as $index => $action) {
        $event = ['event_id' => (string) Str::uuid(), 'action' => $action, 'target' => $target,
            'payload' => $action === 'details' ? ['remarks' => 'Preserved original report'] : ['latitude' => 22, 'longitude' => 73],
            'captured_at' => now()->subMinutes(9 - $index)->toIso8601String(), 'previous_event_id' => $previous];
        $this->postJson('/api/v1/field-visit-events', $event, ['Idempotency-Key' => $event['event_id']])->assertSuccessful();
        $this->postJson('/api/v1/field-visit-events', $event, ['Idempotency-Key' => $event['event_id']])->assertSuccessful()->assertHeader('Idempotency-Replayed', 'true');
        $previous = $event['event_id'];
    }
    $visit = Visit::query()->sole();
    expect($visit->id)->toBe($id)->and($visit->visit_status)->toBe('completed')
        ->and($visit->unplanned_reason)->toBe($this->requestResolution['reason'])
        ->and($visit->remarks)->toBe('Preserved original report')
        ->and($record->fresh()->resolved_visit_id)->toBe($id)->and($record->fresh()->status)->toBe('recovered')
        ->and(Arr::sortRecursive($record->fresh()->snapshot['request']))->toBe(Arr::sortRecursive($this->call))
        ->and($record->fresh()->resolution_snapshot['intention']['status'])->toBe($status);
    $this->getJson('/api/v1/flexible-plans?start='.today()->toDateString())->assertJsonPath('data.completed', 0);
    expect(app(FieldSyncRecoveryService::class)->canSubmitRecoveredDcr($visit->salesDcr))->toBeFalse();
})->with(['changed' => 'active', 'cancelled' => 'cancelled']);

it('requires explicit confirmations fresh review evidence and an assigned distinct manager', function () {
    $failed = failPlanOpening($this);
    $this->postJson('/api/v1/field-sync-recoveries/'.$this->operation.'/request', [...$this->requestResolution, 'confirmed_actual_visit' => false])->assertUnprocessable();
    $record = requestPlanResolution($this);
    $this->rep->givePermissionTo('Review:FieldSyncRecovery');
    $this->patchJson('/api/v1/field-sync-recoveries/'.$record->id.'/review', ['decision' => 'approved', 'note' => 'Own approval attempt'])->assertForbidden();
    Sanctum::actingAs($this->unrelated);
    $this->patchJson('/api/v1/field-sync-recoveries/'.$record->id.'/review', ['decision' => 'approved', 'note' => 'Other reviewer attempt'])->assertForbidden();
    Sanctum::actingAs($this->manager);
    $base = ['decision' => 'approved', 'note' => 'Evidence checked by manager', 'review_token' => $record->reviewToken()];
    $this->patchJson('/api/v1/field-sync-recoveries/'.$record->id.'/review', $base)->assertUnprocessable();
    $this->patchJson('/api/v1/field-sync-recoveries/'.$record->id.'/review', [...$base, 'confirmed_actual_visit' => true, 'review_token' => str_repeat('a', 64)])->assertConflict();
    expect(Visit::query()->count())->toBe(0);
});

it('invalidates review when the plan changes again and requires fresh evidence', function () {
    failPlanOpening($this);
    $record = requestPlanResolution($this);
    $oldToken = $record->reviewToken();
    $this->postJson('/api/v1/flexible-plans', [...$this->proposal, 'expected_version' => 2, 'notes' => 'Changed again'])->assertSuccessful();
    Sanctum::actingAs($this->manager);
    $this->patchJson('/api/v1/field-sync-recoveries/'.$record->id.'/review', ['decision' => 'approved', 'note' => 'Outdated evidence', 'confirmed_actual_visit' => true, 'review_token' => $oldToken])->assertConflict();
    Sanctum::actingAs($this->rep);
    $fresh = requestPlanResolution($this);
    expect($fresh->reviewToken())->not->toBe($oldToken)->and($fresh->review_history)->toHaveCount(1);
    approvePlanResolution($this, $fresh);
    $this->postJson('/api/v1/visits/direct', $this->call, ['Idempotency-Key' => $this->operation])->assertSuccessful();
});

it('rechecks locks policy ownership permissions expiry and reviewer authority at posting', function (string $change, int $expected) {
    failPlanOpening($this);
    $record = requestPlanResolution($this);
    approvePlanResolution($this, $record);
    if ($change === 'locked') {
        SalesDcr::query()->where('user_id', $this->rep->id)->update(['approval_status' => 'approved']);
    } elseif ($change === 'visibility') {
        $this->doctor->update(['owner_id' => $this->unrelated->id]);
    } elseif ($change === 'permission') {
        $this->rep->revokePermissionTo('Create:Visit');
    } elseif ($change === 'reviewer') {
        $this->manager->revokePermissionTo('Review:FieldSyncRecovery');
    } elseif ($change === 'expiry') {
        $record->update(['expires_at' => now()->subSecond()]);
    } elseif ($change === 'plan') {
        $this->postJson('/api/v1/flexible-plans', [...$this->proposal, 'expected_version' => 2, 'notes' => 'Changed after approval'])->assertSuccessful();
    } elseif ($change === 'policy') {
        $revision = $this->settings->saveProposal($this->rep, ['mode' => 'formal_mtp', 'rules' => app(FieldPlanningService::class)->preset(FieldPlanningMode::FormalMtp), 'reason' => 'Formal policy now required'], 1);
        $this->settings->activate($this->rep, $revision->id, 2);
    }
    $this->postJson('/api/v1/visits/direct', $this->call, ['Idempotency-Key' => $this->operation])->assertStatus($expected);
    expect(Visit::query()->count())->toBe(0)->and($record->fresh()->recovered_at)->toBeNull();
})->with(['locked' => ['locked', 422], 'visibility' => ['visibility', 409], 'permission' => ['permission', 403],
    'reviewer revoked' => ['reviewer', 409], 'expired' => ['expiry', 409], 'changed plan' => ['plan', 409], 'formal policy' => ['policy', 422]]);

it('rejects date and future-time evidence and scopes the original operation to its owner', function (string $change) {
    $this->call['captured_at'] = $change === 'future' ? now()->addHour()->toIso8601String() : today()->subDay()->toIso8601String();
    failPlanOpening($this);
    $this->postJson('/api/v1/field-sync-recoveries/'.$this->operation.'/request', $this->requestResolution)->assertUnprocessable();
    Sanctum::actingAs($this->unrelated);
    $this->postJson('/api/v1/field-sync-recoveries/'.$this->operation.'/request', $this->requestResolution)->assertNotFound();
})->with(['future', 'date mismatch']);

it('retains rejected requests and prevents changing the recovered target or audit link', function () {
    failPlanOpening($this, 'cancelled');
    $record = requestPlanResolution($this);
    Sanctum::actingAs($this->manager);
    $this->patchJson('/api/v1/field-sync-recoveries/'.$record->id.'/review', ['decision' => 'rejected', 'note' => 'More actual-visit evidence is needed', 'review_token' => $record->reviewToken()])->assertSuccessful();
    Sanctum::actingAs($this->rep);
    $this->postJson('/api/v1/visits/direct', $this->call, ['Idempotency-Key' => $this->operation])->assertConflict();
    expect(Visit::query()->count())->toBe(0)->and($record->fresh()->snapshot['request']['flexible_plan_version'])->toBe(1);
    $record = requestPlanResolution($this);
    approvePlanResolution($this, $record);
    $this->postJson('/api/v1/visits/direct', $this->call, ['Idempotency-Key' => $this->operation])->assertSuccessful();
    $event = ['event_id' => (string) Str::uuid(), 'action' => 'check_in',
        'target' => [...Arr::except($this->call, ['captured_at']), 'flexible_plan_version' => 2],
        'captured_at' => now()->subMinutes(9)->toIso8601String(), 'payload' => ['latitude' => 22, 'longitude' => 73]];
    $this->postJson('/api/v1/field-visit-events', $event, ['Idempotency-Key' => $event['event_id']])->assertConflict();
    expect(Visit::query()->sole()->start_time)->toBeNull();
    expect(fn () => $record->fresh()->update(['resolved_visit_id' => null]))->toThrow(HttpException::class);
});

it('requires actual capture metadata and does not grant a reporting-window exception', function (string $case) {
    if ($case === 'missing_capture') {
        unset($this->call['captured_at']);
    }
    failPlanOpening($this);
    if ($case === 'closed_date') {
        VisitPreference::current()->update(['allow_backdated_dcr' => false]);
        $this->travel(1)->days();
    }
    $this->postJson('/api/v1/field-sync-recoveries/'.$this->operation.'/request', $this->requestResolution)->assertUnprocessable();
    expect(Visit::query()->count())->toBe(0);
})->with(['missing_capture', 'closed_date']);
