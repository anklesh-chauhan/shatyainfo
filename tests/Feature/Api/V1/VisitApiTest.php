<?php

use App\Models\Media;
use App\Models\Visit;
use App\Models\VisitFeedbackQuestion;
use App\Models\VisitOutcome;
use App\Models\VisitPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('loads and saves the mobile visit workspace', function () {
    $user = reportingUser('VisitWorkspaceApi');
    $territory = reportingTerritory('Workspace Territory');
    $patch = reportingPatch($territory, 'Workspace Patch');
    $outcome = VisitOutcome::create(['label' => 'Interested', 'code' => 'INTERESTED']);
    $question = VisitFeedbackQuestion::create(['question' => 'How was the call?', 'code' => 'CALL', 'is_active' => true]);
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
        ->assertJsonPath('data.workspace.remarks', 'Discussed next order');
});

it('checks in a visit with gps', function () {
    $user = reportingUser('VisitCheckInApi');
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

    $user = reportingUser('VisitPhotoApi');
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
    $user = reportingUser('VisitCheckOutApi');
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
    $owner = reportingUser('VisitOwnerApi');
    $other = reportingUser('VisitOtherApi');
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
