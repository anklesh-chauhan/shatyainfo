<?php

use App\Models\FieldSyncReceipt;
use App\Models\Visit;
use App\Models\VisitPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(now()->setTime(15, 0));
    $this->rep = reportingUser('OfflineRep');
    $territory = reportingTerritory('Offline territory');
    $patch = reportingPatch($territory, 'Offline patch');
    $this->actingAs($this->rep);
    VisitPreference::current()->update([
        'enable_check_in' => true, 'enable_check_out' => true,
        'require_gps' => true, 'require_check_in_image' => false,
        'require_check_out_image' => false, 'enforce_minimum_duration' => false,
    ]);
    $this->visit = Visit::withoutEvents(fn () => Visit::query()->create([
        'document_number' => 'OFF-'.Str::uuid(), 'employee_id' => $this->rep->id,
        'territory_id' => $territory->id, 'patch_id' => $patch->id,
        'visit_date' => today(), 'visit_type' => 'unplanned', 'visit_status' => 'draft',
        'approval_status' => 'draft', 'created_by' => $this->rep->id, 'updated_by' => $this->rep->id,
    ]));
    Sanctum::actingAs($this->rep);
});

it('replays an acknowledged offline request without changing its capture time', function () {
    $payload = ['latitude' => 19.07, 'longitude' => 72.87, 'recorded_at' => now()->subHours(2)->toIso8601String()];
    $headers = ['Idempotency-Key' => (string) Str::uuid()];
    $path = '/api/v1/visits/'.$this->visit->id.'/check-in';
    $first = $this->postJson($path, $payload, $headers)->assertSuccessful();
    $second = $this->postJson($path, $payload, $headers)->assertSuccessful()->assertHeader('Idempotency-Replayed', 'true');
    expect($second->json())->toBe($first->json())
        ->and($this->visit->fresh()->start_time->toIso8601String())->toBe($payload['recorded_at'])
        ->and(FieldSyncReceipt::count())->toBe(1);
});

it('rejects reuse of the same key with changed data', function () {
    $key = ['Idempotency-Key' => (string) Str::uuid()];
    $path = '/api/v1/visits/'.$this->visit->id.'/check-in';
    $this->postJson($path, ['latitude' => 19, 'longitude' => 72], $key)->assertSuccessful();
    $this->postJson($path, ['latitude' => 20, 'longitude' => 72], $key)->assertConflict();
});

it('rejects future and stale captures without saving receipts', function ($offset) {
    $this->postJson('/api/v1/visits/'.$this->visit->id.'/check-in', [
        'latitude' => 19, 'longitude' => 72,
        'recorded_at' => now()->addHours($offset)->toIso8601String(),
    ], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('recorded_at');
    expect(FieldSyncReceipt::count())->toBe(0)->and($this->visit->fresh()->start_time)->toBeNull();
})->with([1, -192]);

it('does not allow another user to replay a receipt', function () {
    $headers = ['Idempotency-Key' => (string) Str::uuid()];
    $path = '/api/v1/visits/'.$this->visit->id.'/check-in';
    $this->postJson($path, ['latitude' => 19, 'longitude' => 72], $headers)->assertSuccessful();
    Sanctum::actingAs(reportingUser('OtherOfflineRep'));
    $this->postJson($path, ['latitude' => 19, 'longitude' => 72], $headers)->assertNotFound();
});

it('uses captured duration rather than upload time for minimum visit duration', function () {
    VisitPreference::current()->update(['enforce_minimum_duration' => true, 'minimum_duration_minutes' => 20]);
    $this->visit->update(['start_time' => now()->subHours(2), 'visit_status' => 'started']);
    $this->postJson('/api/v1/visits/'.$this->visit->id.'/check-out', [
        'latitude' => 19, 'longitude' => 72, 'recorded_at' => now()->subHours(2)->addMinutes(3)->toIso8601String(),
    ], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('duration');
});

it('rejects check out before check in', function () {
    $this->visit->update(['start_time' => now()->subHour(), 'visit_status' => 'started']);
    $this->postJson('/api/v1/visits/'.$this->visit->id.'/check-out', [
        'latitude' => 19, 'longitude' => 72, 'recorded_at' => now()->subHours(2)->toIso8601String(),
    ])->assertUnprocessable()->assertJsonValidationErrors('recorded_at');
});
