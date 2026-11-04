<?php

use App\Enums\SgipStockSource;
use App\Jobs\ProcessMediaUploadJob;
use App\Models\AccountMaster;
use App\Models\ContactDetail;
use App\Models\FieldOrderState;
use App\Models\FieldVisitEvent;
use App\Models\InventoryStock;
use App\Models\SalesDcr;
use App\Models\SalesTourPlan;
use App\Models\SalesTourPlanDetail;
use App\Models\SgipDistribution;
use App\Models\Visit;
use App\Models\VisitPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(now()->setTime(15, 0));
    $this->rep = reportingUser('FieldEventRep');
    $territory = reportingTerritory('Field event territory');
    $patch = reportingPatch($territory, 'Field event patch');
    $doctor = reportingDoctor($this->rep, 'Dr Event');
    $patch->companies()->attach($doctor->id);
    Sanctum::actingAs($this->rep);
    VisitPreference::current()->update([
        'enable_check_in' => true, 'enable_check_out' => true, 'require_gps' => true,
        'require_check_in_image' => false, 'require_check_out_image' => false,
        'require_general_visit_image' => false, 'enforce_minimum_duration' => false,
        'field_rules' => [],
    ]);
    $plan = SalesTourPlan::forceCreate(['user_id' => $this->rep->id,
        'month' => now()->format('Y-m'), 'approval_status' => 'approved',
        'created_by' => $this->rep->id, 'updated_by' => $this->rep->id]);
    $detail = SalesTourPlanDetail::forceCreate(['sales_tour_plan_id' => $plan->id,
        'date' => today(), 'territory_id' => $territory->id, 'remarks' => 'Original tour note',
        'created_by' => $this->rep->id, 'updated_by' => $this->rep->id]);
    $detail->patches()->attach($patch->id);
    $this->target = ['detail_id' => $detail->id, 'patch_id' => $patch->id, 'company_id' => $doctor->id];
});

function queuedFieldEvent(array $target, string $action, array $payload = [], ?string $previous = null, ?string $captured = null): array
{
    return ['event_id' => (string) Str::uuid(), 'target' => $target, 'action' => $action,
        'payload' => $payload, 'captured_at' => $captured ?? now()->subMinutes(10)->toIso8601String(),
        'previous_event_id' => $previous];
}

it('applies planned call events once and preserves original capture times and notes', function () {
    $checkIn = queuedFieldEvent($this->target, 'check_in', ['latitude' => 19.07, 'longitude' => 72.87], captured: now()->subMinutes(30)->toIso8601String());
    $send = fn (array $event) => $this->postJson('/api/v1/field-visit-events', $event, ['Idempotency-Key' => $event['event_id']]);
    $first = $send($checkIn)->assertSuccessful();
    $visitId = $first->json('data.id');
    $send($checkIn)->assertSuccessful()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $visitId);
    $firstNote = queuedFieldEvent($this->target, 'details', ['remarks' => 'First field note'], $checkIn['event_id']);
    $send($firstNote)->assertSuccessful();
    $secondNote = queuedFieldEvent($this->target, 'details', ['remarks' => 'Second device note']);
    $send($secondNote)->assertSuccessful();
    $send($secondNote)->assertSuccessful()->assertHeader('Idempotency-Replayed', 'true');
    $checkout = queuedFieldEvent($this->target, 'check_out', ['latitude' => 19.07, 'longitude' => 72.87], $firstNote['event_id'], now()->subMinutes(2)->toIso8601String());
    $send($checkout)->assertSuccessful()->assertJsonPath('data.visit_status', 'completed');
    expect(Visit::query()->count())->toBe(1)
        ->and(FieldVisitEvent::query()->count())->toBe(4);
    $visit = Visit::query()->findOrFail($visitId);
    expect($visit->start_time->toIso8601String())->toBe($checkIn['captured_at'])
        ->and($visit->end_time->toIso8601String())->toBe($checkout['captured_at'])
        ->and($visit->remarks)->toBe("Original tour note\nFirst field note\nSecond device note");
    expect(FieldVisitEvent::query()->where('action', 'details')->first()->payload['remarks'])->toBe('First field note');
});

it('rejects a missing predecessor without applying a visit or DCR', function () {
    $event = queuedFieldEvent($this->target, 'check_in', ['latitude' => 19, 'longitude' => 72], (string) Str::uuid());
    $this->postJson('/api/v1/field-visit-events', $event, ['Idempotency-Key' => $event['event_id']])->assertConflict();
    expect(FieldVisitEvent::query()->count())->toBe(0)->and(Visit::query()->count())->toBe(0)->and(SalesDcr::query()->count())->toBe(0);
});

it('does not allow another employee to submit work against the tour', function () {
    Sanctum::actingAs(reportingUser('OtherFieldEventRep'));
    $event = queuedFieldEvent($this->target, 'check_in', ['latitude' => 19, 'longitude' => 72]);
    $this->postJson('/api/v1/field-visit-events', $event, ['Idempotency-Key' => $event['event_id']])->assertNotFound();
    expect(FieldVisitEvent::query()->count())->toBe(0);
});

it('requires the replay key and validates GPS and capture times', function () {
    $event = queuedFieldEvent($this->target, 'check_in', ['latitude' => 19, 'longitude' => 72]);
    $this->postJson('/api/v1/field-visit-events', $event)->assertUnprocessable()->assertJsonValidationErrors('event_id');
    $badGps = [...$event, 'payload' => ['latitude' => 95, 'longitude' => 72]];
    $this->postJson('/api/v1/field-visit-events', $badGps, ['Idempotency-Key' => $event['event_id']])
        ->assertUnprocessable()->assertJsonValidationErrors('payload.latitude');
    $future = [...$event, 'event_id' => (string) Str::uuid(), 'captured_at' => now()->addHour()->toIso8601String()];
    $this->postJson('/api/v1/field-visit-events', $future, ['Idempotency-Key' => $future['event_id']])->assertUnprocessable();
    expect(FieldVisitEvent::query()->count())->toBe(0);
});

it('does not append work to a locked DCR', function () {
    $event = queuedFieldEvent($this->target, 'check_in', ['latitude' => 19, 'longitude' => 72]);
    $visitId = $this->postJson('/api/v1/field-visit-events', $event, ['Idempotency-Key' => $event['event_id']])->assertSuccessful()->json('data.id');
    $visit = Visit::query()->findOrFail($visitId);
    $visit->salesDcr()->update(['approval_status' => 'approved']);
    $note = queuedFieldEvent($this->target, 'details', ['remarks' => 'Must not be appended'], $event['event_id']);
    $this->postJson('/api/v1/field-visit-events', $note, ['Idempotency-Key' => $note['event_id']])->assertUnprocessable();
    expect(FieldVisitEvent::query()->count())->toBe(1)->and($visit->fresh()->remarks)->toBe('Original tour note');
});

it('keeps accepted field events append-only', function () {
    $event = queuedFieldEvent($this->target, 'check_in', ['latitude' => 19, 'longitude' => 72]);
    $this->postJson('/api/v1/field-visit-events', $event, ['Idempotency-Key' => $event['event_id']])->assertSuccessful();
    $record = FieldVisitEvent::query()->firstOrFail();
    expect(fn () => $record->update(['action' => 'check_out']))->toThrow(LogicException::class);
    expect(fn () => $record->delete())->toThrow(LogicException::class);
});

it('stores a queued visit photo once and retains its capture metadata', function () {
    Storage::fake('public');
    Bus::fake([ProcessMediaUploadJob::class]);
    $event = queuedFieldEvent($this->target, 'check_in', ['latitude' => 19, 'longitude' => 72]);
    $this->postJson('/api/v1/field-visit-events', $event, ['Idempotency-Key' => $event['event_id']])->assertSuccessful();
    $photo = queuedFieldEvent($this->target, 'photo', ['tag' => 'check-in', 'latitude' => 19, 'longitude' => 72], $event['event_id']);
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
    $send = fn () => $this->post('/api/v1/field-visit-events', [...$photo,
        'photo' => UploadedFile::fake()->createWithContent('visit.png', $png)], ['Idempotency-Key' => $photo['event_id']]);
    $send()->assertSuccessful();
    $send()->assertSuccessful()->assertHeader('Idempotency-Replayed', 'true');
    $record = FieldVisitEvent::query()->where('event_id', $photo['event_id'])->firstOrFail();
    expect($record->payload['photo_sha256'])->toBe(hash('sha256', $png))
        ->and($record->visit->media()->count())->toBe(1);
    $checkout = queuedFieldEvent($this->target, 'check_out', ['latitude' => 19, 'longitude' => 72], $photo['event_id']);
    $this->postJson('/api/v1/field-visit-events', $checkout, ['Idempotency-Key' => $checkout['event_id']])->assertSuccessful()->assertJsonPath('data.visit_status', 'completed');
    $finalPhoto = queuedFieldEvent($this->target, 'photo', ['tag' => 'check-out', 'latitude' => 19, 'longitude' => 72], $checkout['event_id']);
    $this->post('/api/v1/field-visit-events', [...$finalPhoto,
        'photo' => UploadedFile::fake()->createWithContent('checkout.png', $png)], ['Idempotency-Key' => $finalPhoto['event_id']])->assertSuccessful();
    expect($record->visit->media()->count())->toBe(2);
});

it('reconciles samples once using server prices and reserves stock until approval', function () {
    $location = reportingLocation('Offline Sample HQ');
    $item = reportingSampleItem('OFFLINE-SAMPLE', 'Offline Sample');
    $item->forceFill(['selling_price' => 10])->save();
    VisitPreference::current()->update(['sgip_stock_source' => SgipStockSource::Headquarters, 'sgip_hq_location_id' => $location->id]);
    $stock = InventoryStock::query()->create(['item_master_id' => $item->id, 'location_master_id' => $location->id,
        'quantity_on_hand' => 5, 'quantity_reserved' => 0, 'quantity_available' => 5]);
    $checkin = queuedFieldEvent($this->target, 'check_in', ['latitude' => 19, 'longitude' => 72]);
    $this->postJson('/api/v1/field-visit-events', $checkin, ['Idempotency-Key' => $checkin['event_id']])->assertSuccessful();
    $samples = queuedFieldEvent($this->target, 'samples', ['expected_version' => null,
        'items' => [['item_master_id' => $item->id, 'quantity' => 3]]], $checkin['event_id']);
    $this->postJson('/api/v1/field-visit-events', $samples, ['Idempotency-Key' => $samples['event_id']])->assertSuccessful();
    $this->postJson('/api/v1/field-visit-events', $samples, ['Idempotency-Key' => $samples['event_id']])->assertSuccessful()->assertHeader('Idempotency-Replayed', 'true');
    $distribution = SgipDistribution::query()->firstOrFail();
    expect($distribution->approval_status)->toBe('submitted')->and((float) $distribution->total_value)->toBe(30.0)
        ->and((float) $stock->fresh()->quantity_reserved)->toBe(3.0)->and((float) $stock->fresh()->quantity_on_hand)->toBe(5.0);
    $this->getJson('/api/v1/sample-ledger')->assertSuccessful()->assertJsonPath('data.sources.0.stock.0.available', 2);
    $distribution->approve();
    expect((float) $stock->fresh()->quantity_on_hand)->toBe(2.0)->and((float) $stock->fresh()->quantity_reserved)->toBe(0.0);
    $distribution->fresh()->approve();
    expect((float) $stock->fresh()->quantity_on_hand)->toBe(2.0);
});

it('rejects sample stock changes without creating a distribution or consuming stock', function () {
    $location = reportingLocation('Low Sample HQ');
    $item = reportingSampleItem('OFFLINE-LOW', 'Limited Sample');
    VisitPreference::current()->update(['sgip_stock_source' => SgipStockSource::Headquarters, 'sgip_hq_location_id' => $location->id]);
    $stock = InventoryStock::query()->create(['item_master_id' => $item->id, 'location_master_id' => $location->id,
        'quantity_on_hand' => 1, 'quantity_reserved' => 0, 'quantity_available' => 1]);
    $checkin = queuedFieldEvent($this->target, 'check_in', ['latitude' => 19, 'longitude' => 72]);
    $this->postJson('/api/v1/field-visit-events', $checkin, ['Idempotency-Key' => $checkin['event_id']])->assertSuccessful();
    $samples = queuedFieldEvent($this->target, 'samples', ['expected_version' => null,
        'items' => [['item_master_id' => $item->id, 'quantity' => 3]]], $checkin['event_id']);
    $this->postJson('/api/v1/field-visit-events', $samples, ['Idempotency-Key' => $samples['event_id']])->assertConflict();
    expect(SgipDistribution::query()->count())->toBe(0)->and((float) $stock->fresh()->quantity_reserved)->toBe(0.0)
        ->and(FieldVisitEvent::query()->where('action', 'samples')->count())->toBe(0);
});

it('releases reserved samples when a submitted distribution is rejected', function () {
    $location = reportingLocation('Rejected Sample HQ');
    $item = reportingSampleItem('OFFLINE-REJECT', 'Returned Sample');
    VisitPreference::current()->update(['sgip_stock_source' => SgipStockSource::Headquarters, 'sgip_hq_location_id' => $location->id]);
    $stock = InventoryStock::query()->create(['item_master_id' => $item->id, 'location_master_id' => $location->id,
        'quantity_on_hand' => 5, 'quantity_reserved' => 0, 'quantity_available' => 5]);
    $checkin = queuedFieldEvent($this->target, 'check_in', ['latitude' => 19, 'longitude' => 72]);
    $this->postJson('/api/v1/field-visit-events', $checkin, ['Idempotency-Key' => $checkin['event_id']])->assertSuccessful();
    $samples = queuedFieldEvent($this->target, 'samples', ['expected_version' => null,
        'items' => [['item_master_id' => $item->id, 'quantity' => 2]]], $checkin['event_id']);
    $this->postJson('/api/v1/field-visit-events', $samples, ['Idempotency-Key' => $samples['event_id']])->assertSuccessful();
    SgipDistribution::query()->firstOrFail()->update(['approval_status' => 'rejected']);
    expect((float) $stock->fresh()->quantity_reserved)->toBe(0.0)->and((float) $stock->fresh()->quantity_on_hand)->toBe(5.0);
});

it('merges order fields across devices by UTC edit time without duplicating drafts', function () {
    $company = AccountMaster::query()->findOrFail($this->target['company_id']);
    $contact = ContactDetail::forceCreate(['first_name' => 'Order Contact', 'created_by' => $this->rep->id, 'updated_by' => $this->rep->id]);
    $company->contactDetails()->attach($contact->id);
    $item = reportingSampleItem('ORDER-ONE', 'Order Product');
    $item->forceFill(['selling_price' => 12.5])->save();
    $send = fn (array $event) => $this->postJson('/api/v1/field-visit-events', $event, ['Idempotency-Key' => $event['event_id']]);
    $checkin = queuedFieldEvent($this->target, 'check_in', ['latitude' => 19, 'longitude' => 72]);
    $send($checkin)->assertSuccessful();
    $orderId = (string) Str::uuid();
    $clock = fn (int $minutes) => now()->subMinutes($minutes)->utc()->toIso8601String();
    $first = queuedFieldEvent($this->target, 'order', ['order_id' => $orderId, 'changes' => [
        ['field' => 'line:'.$item->id, 'value' => 4, 'updated_at' => $clock(20)],
        ['field' => 'description', 'value' => 'New description', 'updated_at' => $clock(20)],
    ]], captured: $clock(10));
    $send($first)->assertSuccessful();
    $send($first)->assertSuccessful()->assertHeader('Idempotency-Replayed', 'true');
    $second = queuedFieldEvent($this->target, 'order', ['order_id' => $orderId, 'changes' => [
        ['field' => 'line:'.$item->id, 'value' => 2, 'updated_at' => $clock(25)],
        ['field' => 'description', 'value' => 'Stale description', 'updated_at' => $clock(25)],
        ['field' => 'delivery_date', 'value' => today()->addDay()->toDateString(), 'updated_at' => $clock(25)],
    ]], captured: $clock(5));
    $send($second)->assertSuccessful();
    $state = FieldOrderState::query()->sole();
    expect($state->salesOrder->description)->toBe('New description')
        ->and((float) $state->salesOrder->items()->sole()->quantity)->toBe(4.0)
        ->and((float) $state->salesOrder->total)->toBe(50.0)
        ->and($state->salesOrder->delivery_date->toDateString())->toBe(today()->addDay()->toDateString());
    $state->salesOrder->forceFill(['description' => 'Edited in ERP'])->save();
    $send(queuedFieldEvent($this->target, 'order', ['order_id' => $orderId, 'changes' => [
        ['field' => 'description', 'value' => 'Older device edit', 'updated_at' => $clock(15)],
    ]], captured: $clock(2)))->assertSuccessful();
    expect($state->salesOrder->fresh()->description)->toBe('Edited in ERP');
    $state->salesOrder->forceFill(['tax' => 5])->save();
    $send(queuedFieldEvent($this->target, 'order', ['order_id' => $orderId, 'changes' => [
        ['field' => 'line:'.$item->id, 'value' => 10, 'updated_at' => $clock(1)],
    ]], captured: $clock(1)))->assertConflict();
    expect((float) $state->salesOrder->items()->sole()->quantity)->toBe(4.0);
});
