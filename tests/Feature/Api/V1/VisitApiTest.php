<?php

use App\Models\FieldGpsPoint;
use App\Models\JobRole;
use App\Models\Media;
use App\Models\Position;
use App\Models\SalesDcr;
use App\Models\Visit;
use App\Models\VisitFeedbackQuestion;
use App\Models\VisitOutcome;
use App\Models\VisitPreference;
use App\Models\VisitPurpose;
use App\Models\VisitType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('filters the register using Admin team visibility while keeping field work owner-scoped', function () {
    $manager = fieldReportingUser('Register manager');
    $rep = fieldReportingUser('Visible visit rep');
    $outsider = fieldReportingUser('Outside visit rep');
    $territory = reportingTerritory('Visible register territory');
    $otherTerritory = reportingTerritory('Unrelated register territory');
    $patch = reportingPatch($territory, 'Visible register patch');
    $outsidePatch = reportingPatch($otherTerritory, 'Other register patch');
    $role = JobRole::create(['name' => 'Visits register manager', 'code' => 'VREGMGR', 'level' => 1]);
    $position = Position::create(['name' => 'Visits register manager', 'code' => 'VREGPOS', 'job_role_id' => $role->id]);
    $position->territories()->attach($territory);
    $manager->employee->positions()->attach($position, ['is_primary' => true]);
    grantPagePermissions($manager, 'ViewOwnTerritory:Visit');

    $make = fn ($user, $visitPatch, array $attributes): Visit => Visit::withoutEvents(fn (): Visit => Visit::forceCreate([
        'employee_id' => $user->id, 'created_by' => $user->id,
        'territory_id' => $visitPatch->territory_id, 'patch_id' => $visitPatch->id,
        'visit_date' => today(), 'visit_type' => 'unplanned', 'visit_status' => 'draft',
        ...$attributes,
    ]));
    $own = $make($manager, $patch, ['document_number' => 'VIS-OWN']);
    $visible = $make($rep, $patch, [
        'document_number' => 'VIS-VISIBLE', 'approval_status' => 'pending',
        'visit_status' => 'completed', 'remarks' => 'Reviewed delivery questions',
    ]);
    $outside = $make($outsider, $outsidePatch, ['document_number' => 'VIS-OUTSIDE']);

    Sanctum::actingAs($manager->fresh());
    $base = '/api/v1/visits';
    $this->getJson($base)->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $own->id)->assertJsonMissingPath('meta.can_view_team');
    $this->getJson($base.'?register=1')->assertOk()->assertJsonPath('meta.can_view_team', true)
        ->assertJsonPath('data.0.can_open_workspace', true);
    $this->getJson($base.'?register=1&scope=team&search=VISIBLE&approval_status=pending&employee_id='.$rep->id)
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $visible->id)
        ->assertJsonPath('data.0.can_open_workspace', false)
        ->assertJsonPath('data.0.actions', [])
        ->assertJsonCount(1, 'meta.employees')
        ->assertJsonPath('meta.employees.0.id', $rep->id);
    $this->getJson($base.'?register=1&scope=team&employee_id='.$outsider->id)->assertOk()->assertJsonCount(0, 'data');
    $teamDetail = $base.'/'.$visible->id.'/register?scope=team';
    $this->getJson($teamDetail)->assertOk()->assertJsonPath('data.remarks', 'Reviewed delivery questions')
        ->assertJsonMissingPath('data.workspace')->assertJsonMissingPath('data.checkin_latitude');
    $this->getJson($base.'/'.$outside->id.'/register?scope=team')->assertNotFound();
    $this->getJson($base.'/'.$visible->id)->assertNotFound();
    $this->patchJson($base.'/'.$visible->id, ['remarks' => 'Cross-user write'])->assertNotFound();

    $manager->revokePermissionTo('ViewOwnTerritory:Visit');
    Sanctum::actingAs($manager->fresh());
    $this->getJson($teamDetail)->assertNotFound();
    $this->getJson($base.'?register=1')->assertOk()->assertJsonPath('meta.can_view_team', false);
    $manager->revokePermissionTo('View:Visit');
    Sanctum::actingAs($manager->fresh());
    $this->getJson($teamDetail)->assertForbidden();
});

it('only trashes or restores eligible drafts and verifies an entire selection before writing', function () {
    $user = fieldReportingUser('Visits lifecycle');
    $outside = reportingUser('Unrelated lifecycle visit');
    $territory = reportingTerritory('Visit lifecycle territory');
    $patch = reportingPatch($territory, 'Visit lifecycle patch');
    $closedDcr = SalesDcr::query()->create([
        'user_id' => $user->id, 'dcr_date' => today(), 'approval_status' => 'approved',
    ]);
    $make = fn (string $number, array $attributes = []): Visit => Visit::withoutEvents(fn (): Visit => Visit::forceCreate([
        'document_number' => $number, 'employee_id' => $user->id, 'created_by' => $user->id,
        'territory_id' => $territory->id, 'patch_id' => $patch->id, 'visit_date' => today(),
        'visit_type' => 'unplanned', 'visit_status' => 'draft', 'approval_status' => 'draft',
        ...$attributes,
    ]));
    $first = $make('VIS-LIFE-1');
    $second = $make('VIS-LIFE-2');
    $journalVisit = $make('VIS-LIFE-JOURNAL', ['client_call_id' => (string) Str::uuid()]);
    $approvedVisit = $make('VIS-LIFE-APPROVED', ['sales_dcr_id' => $closedDcr->id]);
    $gpsVisit = $make('VIS-LIFE-GPS');
    FieldGpsPoint::query()->create([
        'user_id' => $user->id, 'visit_id' => $gpsVisit->id, 'latitude' => 19, 'longitude' => 72, 'recorded_at' => now(),
    ])->delete();
    $unrelated = $make('VIS-LIFE-OUTSIDE', ['employee_id' => $outside->id, 'created_by' => $outside->id]);
    Sanctum::actingAs($user);

    $base = '/api/v1/visits';
    $run = fn (string $action, array $ids) => $this->postJson($base.'/register-actions', [
        'scope' => 'personal', 'action' => $action, 'ids' => $ids,
    ], ['Idempotency-Key' => (string) Str::uuid()]);
    $run('delete', [$first->id])->assertForbidden();
    grantPagePermissions($user, 'Delete:Visit', 'Restore:Visit', 'ForceDelete:Visit');
    $user->revokePermissionTo('Update:Visit');
    Sanctum::actingAs($user->fresh());
    $this->getJson($base.'/'.$first->id.'/register')->assertOk()->assertJsonPath('data.actions', ['delete']);
    foreach ([$journalVisit, $approvedVisit, $gpsVisit] as $protected) {
        $run('delete', [$first->id, $protected->id])->assertUnprocessable();
        $this->assertNotSoftDeleted('visits', ['id' => $first->id]);
    }
    $run('delete', [$first->id, $unrelated->id])->assertNotFound();
    $this->assertNotSoftDeleted('visits', ['id' => $first->id]);
    $run('delete', [$first->id, $second->id])->assertOk()->assertJsonPath('data.updated', 2);
    $this->assertSoftDeleted('visits', ['id' => $first->id]);
    $this->getJson($base.'?register=1&trash=only')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.can_open_workspace', false);
    $this->getJson($base.'/'.$first->id)->assertNotFound();
    $run('restore', [$first->id, $second->id])->assertForbidden();
    grantPagePermissions($user, 'RestoreAny:Visit');
    Sanctum::actingAs($user->fresh());
    $run('restore', [$first->id, $second->id])->assertOk()->assertJsonPath('data.updated', 2);
    $run('forceDelete', [$first->id])->assertConflict();
    $run('delete', [$first->id])->assertOk();
    $run('forceDelete', [$first->id])->assertOk()->assertJsonPath('data.updated', 1);
    $this->assertDatabaseMissing('visits', ['id' => $first->id]);
    $this->assertNotSoftDeleted('visits', ['id' => $second->id]);
});

it('loads and saves the mobile visit workspace', function () {
    $user = fieldReportingUser('VisitWorkspaceApi');
    $territory = reportingTerritory('Workspace Territory');
    $patch = reportingPatch($territory, 'Workspace Patch');
    $outcome = VisitOutcome::create(['label' => 'Interested', 'code' => 'INTERESTED']);
    $question = VisitFeedbackQuestion::create(['question' => 'How was the call?', 'code' => 'CALL', 'answer_type' => 'rating_1_5', 'is_active' => true]);
    $this->actingAs($user);
    VisitPreference::current()->update(['enable_check_in' => true, 'require_general_visit_image' => false]);
    $visit = Visit::withoutEvents(fn (): Visit => Visit::query()->create([
        'document_number' => 'VIS-WORKSPACE', 'employee_id' => $user->id,
        'territory_id' => $territory->id, 'patch_id' => $patch->id,
        'visit_date' => today(), 'visit_type' => 'planned',
        'visit_status' => 'started', 'start_time' => now(),
        'approval_status' => 'draft',
    ]));
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/order-catalog')->assertForbidden();
    $this->postJson("/api/v1/visits/{$visit->id}/documents", ['type' => 'quote'])->assertForbidden();
    $this->postJson("/api/v1/visits/{$visit->id}/documents", ['type' => 'sales_order'])->assertForbidden();
    $this->getJson('/api/v1/visits/options')->assertSuccessful()
        ->assertJsonPath('data.outcomes.0.label', 'Interested')
        ->assertJsonPath('data.feedback_questions.0.question', 'How was the call?');
    $this->patchJson('/api/v1/visits/'.$visit->id, [
        'visit_outcome_id' => $outcome->id,
        'remarks' => 'Discussed next order',
        'feedback' => [['question_id' => $question->id, 'answer' => 4]],
        'next_follow_up_date' => today()->addDays(3)->toDateString(),
    ])->assertSuccessful()
        ->assertJsonPath('data.workspace.outcome_label', 'Interested')
        ->assertJsonPath('data.workspace.feedback.0.answer', 4);
    $this->getJson('/api/v1/visits/'.$visit->id)->assertSuccessful()
        ->assertJsonPath('data.workspace.remarks', 'Discussed next order')
        ->assertJsonPath('data.workspace.can_create_quote', false)
        ->assertJsonPath('data.workspace.can_create_sales_order', false)
        ->assertJsonPath('data.workspace.documents', []);

    $user->revokePermissionTo('Update:Visit');
    Sanctum::actingAs($user->fresh());
    $this->getJson('/api/v1/visits/'.$visit->id)->assertSuccessful()->assertJsonPath('data.can_update', false);
    $this->patchJson('/api/v1/visits/'.$visit->id, ['remarks' => 'Denied edit'])->assertForbidden();
    expect($visit->fresh()->remarks)->toBe('Discussed next order');
});

it('checks in a visit with gps', function () {
    $user = fieldReportingUser('VisitCheckInApi');
    $territory = reportingTerritory('Visit Terr');
    $patch = reportingPatch($territory, 'Visit Patch');

    $this->actingAs($user);

    VisitPreference::current()->update([
        'enable_check_in' => true,
        'require_gps' => true,
        'require_check_in_image' => false,
    ]);

    $visit = Visit::withoutEvents(fn (): Visit => Visit::query()->create([
        'document_number' => 'VIS-API-'.fake()->unique()->numerify('####'),
        'employee_id' => $user->id,
        'territory_id' => $territory->id,
        'patch_id' => $patch->id,
        'visit_date' => today(),
        'visit_type' => 'unplanned',
        'visit_status' => 'draft',
        'approval_status' => 'draft',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]));

    Sanctum::actingAs($user);

    $this->postJson("/api/v1/visits/{$visit->id}/check-in", [
        'latitude' => 19.0760,
        'longitude' => 72.8777,
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.visit_status', 'started')
        ->assertJsonPath('data.checkin_latitude', 19.076);

    expect($visit->fresh()->visit_status)->toBe('started');
});

it('stores a visit photo with client gps and captured_at', function () {
    Storage::fake('public');
    Queue::fake();

    $user = fieldReportingUser('VisitPhotoApi');
    $territory = reportingTerritory('Photo Terr');
    $patch = reportingPatch($territory, 'Photo Patch');

    $this->actingAs($user);

    VisitPreference::current()->update([
        'enable_check_in' => true,
        'require_gps' => true,
        'require_check_in_image' => false,
    ]);

    $visit = Visit::withoutEvents(fn (): Visit => Visit::query()->create([
        'document_number' => 'VIS-PHOTO-'.fake()->unique()->numerify('####'),
        'employee_id' => $user->id,
        'territory_id' => $territory->id,
        'patch_id' => $patch->id,
        'visit_date' => today(),
        'visit_type' => 'unplanned',
        'visit_status' => 'started',
        'start_time' => now(),
        'checkin_latitude' => 19.07,
        'checkin_longitude' => 72.87,
        'approval_status' => 'draft',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]));

    Sanctum::actingAs($user);

    $capturedAt = now()->subMinute()->toIso8601String();

    $this->post("/api/v1/visits/{$visit->id}/photos", [
        'photo' => UploadedFile::fake()->image('clinic.jpg'),
        'tag' => 'check-in',
        'latitude' => 19.0811,
        'longitude' => 72.8811,
        'captured_at' => $capturedAt,
    ], ['Accept' => 'application/json'])
        ->assertSuccessful()
        ->assertJsonPath('data.latitude', 19.0811)
        ->assertJsonPath('data.longitude', 72.8811)
        ->assertJsonPath('data.tags.0', 'check-in');

    $media = Media::query()->first();

    expect($media)->not->toBeNull()
        ->and((float) $media->latitude)->toBe(19.0811)
        ->and($media->captured_at)->not->toBeNull();
});

it('checks out a visit and completes when no checkout image is required', function () {
    $user = fieldReportingUser('VisitCheckOutApi');
    $territory = reportingTerritory('Out Terr');
    $patch = reportingPatch($territory, 'Out Patch');

    $this->actingAs($user);

    VisitPreference::current()->update([
        'enable_check_in' => true,
        'enable_check_out' => true,
        'enforce_check_in_before_check_out' => true,
        'require_gps' => true,
        'require_check_out_image' => false,
        'enforce_minimum_duration' => false,
    ]);

    $visit = Visit::withoutEvents(fn (): Visit => Visit::query()->create([
        'document_number' => 'VIS-OUT-'.fake()->unique()->numerify('####'),
        'employee_id' => $user->id,
        'territory_id' => $territory->id,
        'patch_id' => $patch->id,
        'visit_date' => today(),
        'visit_type' => 'unplanned',
        'visit_status' => 'started',
        'start_time' => now()->subHour(),
        'checkin_latitude' => 19.07,
        'checkin_longitude' => 72.87,
        'approval_status' => 'draft',
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]));

    Sanctum::actingAs($user);

    $this->postJson("/api/v1/visits/{$visit->id}/check-out", [
        'latitude' => 19.09,
        'longitude' => 72.89,
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.visit_status', 'completed')
        ->assertJsonPath('meta.needs_checkout_image', false);
});

it('forbids visiting another users visit', function () {
    $owner = fieldReportingUser('VisitOwnerApi');
    $other = fieldReportingUser('VisitOtherApi');
    $territory = reportingTerritory('Own Terr');
    $patch = reportingPatch($territory, 'Own Patch');

    $this->actingAs($owner);

    $visit = Visit::withoutEvents(fn (): Visit => Visit::query()->create([
        'document_number' => 'VIS-OWN-'.fake()->unique()->numerify('####'),
        'employee_id' => $owner->id,
        'territory_id' => $territory->id,
        'patch_id' => $patch->id,
        'visit_date' => today(),
        'visit_type' => 'unplanned',
        'visit_status' => 'draft',
        'approval_status' => 'draft',
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ]));

    Sanctum::actingAs($other);

    $this->getJson("/api/v1/visits/{$visit->id}")
        ->assertNotFound();
});

it('checks out with saved required purposes outcome and follow up using preferences', function () {
    $user = fieldReportingUser('RequiredVisitFields');
    $territory = reportingTerritory('Required Visit Territory');
    $patch = reportingPatch($territory, 'Required Visit Patch');
    $this->actingAs($user);
    $prefs = VisitPreference::current();
    $prefs->update([
        'enable_check_in' => true, 'enable_check_out' => true,
        'enforce_check_in_before_check_out' => true,
        'require_gps' => false, 'require_check_in_image' => false,
        'require_check_out_image' => false, 'require_general_visit_image' => false,
        'enforce_minimum_duration' => false,
        'field_rules' => [
            'purpose' => ['visible' => true, 'required' => true, 'editable' => true],
            'outcome' => ['visible' => true, 'required' => true, 'editable' => true],
            'next_follow_up_date' => ['visible' => true, 'required' => true, 'editable' => true],
            'remarks' => ['visible' => false, 'required' => true, 'editable' => false],
        ],
    ]);
    $visit = Visit::withoutEvents(fn (): Visit => Visit::query()->create([
        'document_number' => 'VIS-REQUIRED', 'employee_id' => $user->id,
        'territory_id' => $territory->id, 'patch_id' => $patch->id,
        'visit_date' => today(), 'visit_type' => 'planned',
        'visit_status' => 'draft', 'approval_status' => 'draft',
    ]));
    Sanctum::actingAs($user);
    $this->postJson("/api/v1/visits/{$visit->id}/check-in", [])->assertSuccessful();
    $this->postJson("/api/v1/visits/{$visit->id}/check-out", [])
        ->assertUnprocessable()->assertJsonValidationErrors('checkout');
    $type = VisitType::create(['name' => 'Field Work', 'code' => 'REQUIRED', 'is_active' => true]);
    $purpose = VisitPurpose::create(['name' => 'Product discussion', 'is_active' => true, 'visit_type_id' => $type->id]);
    $outcome = VisitOutcome::create(['label' => 'Interested', 'code' => 'REQUIRED']);
    $this->patchJson("/api/v1/visits/{$visit->id}", [
        'visit_purpose_ids' => [$purpose->id], 'visit_outcome_id' => $outcome->id,
        'next_follow_up_date' => today()->addDay()->toDateString(),
    ])->assertSuccessful();
    $this->getJson("/api/v1/visits/{$visit->id}")->assertSuccessful()
        ->assertJsonPath('data.workspace.preferences.require_gps', false);
    $prefs->update(['field_rules' => [
        'purpose' => ['visible' => true, 'required' => true, 'editable' => false],
        'outcome' => ['visible' => true, 'required' => true, 'editable' => true],
        'next_follow_up_date' => ['visible' => true, 'required' => true, 'editable' => true],
        'remarks' => ['visible' => false, 'required' => true, 'editable' => false],
    ]]);
    $this->patchJson("/api/v1/visits/{$visit->id}", ['visit_purpose_ids' => []])->assertUnprocessable();
    $prefs->update(['require_gps' => true]);
    $this->postJson("/api/v1/visits/{$visit->id}/check-out", [])
        ->assertUnprocessable()->assertJsonValidationErrors(['latitude', 'longitude']);
    $prefs->update(['require_gps' => false, 'require_check_in_image' => true]);
    $this->postJson("/api/v1/visits/{$visit->id}/check-out", [])
        ->assertUnprocessable()->assertJsonValidationErrors('checkin_image_required');
    $prefs->update(['require_check_in_image' => false, 'require_general_visit_image' => true]);
    $this->postJson("/api/v1/visits/{$visit->id}/check-out", [])
        ->assertUnprocessable()->assertJsonValidationErrors('require_general_visit_image');
    $prefs->update(['require_general_visit_image' => false]);
    $this->postJson("/api/v1/visits/{$visit->id}/check-out", [])
        ->assertSuccessful()->assertJsonPath('data.visit_status', 'completed');
});
