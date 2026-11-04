<?php

use App\Enums\SgipStockSource;
use App\Filament\Resources\FieldSyncRecoveries\Pages\ManageFieldSyncRecoveries;
use App\Models\ContactDetail;
use App\Models\FieldOrderState;
use App\Models\FieldSyncReceipt;
use App\Models\FieldSyncRecovery;
use App\Models\FieldVisitEvent;
use App\Models\InventoryStock;
use App\Models\SalesDcr;
use App\Models\SampleIssue;
use App\Models\SampleRequest;
use App\Models\SgipDistribution;
use App\Models\SgipLimit;
use App\Models\Visit;
use App\Models\VisitPreference;
use App\Services\Field\FieldSgipService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(now()->setTime(15, 0));
    $this->rep = reportingUser('ReplacementRep');
    $this->manager = reportingUser('ReplacementManager');
    Permission::findOrCreate('Review:FieldSyncRecovery', 'web');
    $this->manager->givePermissionTo('Review:FieldSyncRecovery');
    Sanctum::actingAs($this->rep);
    $territory = reportingTerritory('Replacement territory');
    $patch = reportingPatch($territory, 'Replacement patch');
    $this->company = reportingDoctor($this->rep, 'Replacement clinic');
    $this->dcr = SalesDcr::query()->create(['user_id' => $this->rep->id, 'dcr_date' => today(), 'approval_status' => 'draft']);
    $this->visit = Visit::query()->create(['document_number' => 'REPLACEMENT-CALL', 'employee_id' => $this->rep->id,
        'visit_date' => today(), 'visit_status' => 'started', 'start_time' => now()->subHour(),
        'sales_dcr_id' => $this->dcr->id, 'territory_id' => $territory->id, 'patch_id' => $patch->id]);
    $this->visit->visitables()->create(['visitable_type' => $this->company::class, 'visitable_id' => $this->company->id]);
    $this->item = reportingSampleItem('REPLACEMENT-ITEM', 'Replacement product');
    $this->item->update(['selling_price' => 10]);
    $location = reportingLocation('Replacement HQ');
    VisitPreference::current()->update(['allow_backdated_dcr' => false, 'enforce_geo_fencing' => false, 'field_rules' => [],
        'sgip_stock_source' => SgipStockSource::Headquarters, 'sgip_hq_location_id' => $location->id]);
    $this->stock = InventoryStock::query()->create(['item_master_id' => $this->item->id, 'location_master_id' => $location->id,
        'quantity_on_hand' => 5, 'quantity_reserved' => 0, 'quantity_available' => 5]);
    $this->packet = ['event_id' => (string) Str::uuid(), 'target' => ['visit_id' => $this->visit->id], 'action' => 'samples',
        'captured_at' => now()->subMinutes(10)->toIso8601String(),
        'payload' => ['expected_version' => null, 'items' => [['item_master_id' => $this->item->id, 'quantity' => 9]]]];
    $this->sendPacket = fn (array $packet) => $this->postJson('/api/v1/field-visit-events', $packet, ['Idempotency-Key' => $packet['event_id']]);
    ($this->sendPacket)($this->packet)->assertConflict();
    $this->record = FieldSyncRecovery::query()->where('operation_id', $this->packet['event_id'])->sole();
    $this->proposal = ['reviewer_id' => $this->manager->id, 'resolution_mode' => 'replacement', 'confirmed_replacement' => true,
        'reason' => 'Physical distribution was three units; correct the original entry error.',
        'replacement' => ['items' => [['item_master_id' => $this->item->id, 'quantity' => 3]], 'sample_issue_id' => null]];
});

function proposeReplacement($test): void
{
    $test->postJson('/api/v1/field-sync-recoveries/'.$test->record->operation_id.'/request', $test->proposal)->assertSuccessful();
}

function approveReplacement($test): void
{
    Sanctum::actingAs($test->manager);
    $test->patchJson('/api/v1/field-sync-recoveries/'.$test->record->id.'/review', ['decision' => 'approved',
        'note' => 'Verified corrected quantities and supporting evidence.', 'confirmed_replacement' => true,
        'review_token' => $test->record->fresh()->reviewToken()])->assertSuccessful();
    Sanctum::actingAs($test->rep);
}

it('posts the corrected sample once and links both immutable events without changing the original request', function () {
    proposeReplacement($this);
    $reserved = $this->record->fresh();
    ($this->sendPacket)([...$this->packet, 'event_id' => $reserved->replacement_event_id, 'payload' => $reserved->replacement_payload])->assertConflict();
    expect(FieldSyncRecovery::query()->count())->toBe(1);
    ($this->sendPacket)($this->packet)->assertConflict();
    $this->getJson('/api/v1/dcrs/'.$this->dcr->id)->assertJsonPath('data.recovery_review_pending', true);
    approveReplacement($this);
    $first = ($this->sendPacket)($this->packet)->assertSuccessful()->assertJsonPath('sync_resolution.kind', 'replacement');
    ($this->sendPacket)($this->packet)->assertSuccessful()->assertHeader('Idempotency-Replayed', 'true');
    $record = $this->record->fresh();
    expect($first->json('sync_resolution.replacement_event_id'))->toBe($record->replacement_event_id)
        ->and($record->replacement_event_id)->not->toBe($record->operation_id)->and($record->snapshot['request'])->toEqual($this->packet)
        ->and(FieldVisitEvent::query()->count())->toBe(2)->and(SgipDistribution::query()->count())->toBe(1)
        ->and((float) $this->stock->fresh()->quantity_reserved)->toBe(3.0)->and((float) $this->stock->fresh()->quantity_on_hand)->toBe(5.0);
    $replacement = FieldVisitEvent::query()->where('event_id', $record->replacement_event_id)->sole();
    $original = FieldVisitEvent::query()->where('event_id', $record->operation_id)->sole();
    expect($replacement->payload['amendment']['replaces_event_id'])->toBe($record->operation_id)
        ->and($replacement->captured_at->equalTo($record->captured_at))->toBeTrue()
        ->and($original->action)->toBe('replaced')->and($original->previous_event_id)->toBe($record->replacement_event_id)
        ->and((float) SgipDistribution::query()->sole()->total_value)->toBe(30.0);
    $next = [...$this->packet, 'event_id' => (string) Str::uuid(), 'action' => 'details', 'previous_event_id' => $record->operation_id,
        'payload' => ['remarks' => 'Following queued note']];
    ($this->sendPacket)($next)->assertSuccessful();
});

it('rolls back the replacement, original acknowledgement and receipt if stock validation fails', function () {
    proposeReplacement($this);
    approveReplacement($this);
    $this->stock->update(['quantity_on_hand' => 1, 'quantity_available' => 1]);
    ($this->sendPacket)($this->packet)->assertConflict();
    expect(FieldVisitEvent::query()->count())->toBe(0)->and(SgipDistribution::query()->count())->toBe(0)
        ->and(FieldSyncReceipt::query()->count())->toBe(0)->and($this->record->fresh()->status)->toBe('approved');
    $this->stock->update(['quantity_on_hand' => 5, 'quantity_available' => 5]);
    ($this->sendPacket)($this->packet)->assertSuccessful();
    expect((float) $this->stock->fresh()->quantity_reserved)->toBe(3.0);
});

it('requires independent confirmation and rejects changed proposals or original payloads', function () {
    $url = '/api/v1/field-sync-recoveries/'.$this->record->operation_id.'/request';
    $this->postJson($url, [...$this->proposal, 'confirmed_replacement' => false])->assertUnprocessable();
    $this->postJson($url, [...$this->proposal, 'reviewer_id' => $this->rep->id])->assertUnprocessable();
    proposeReplacement($this);
    $oldToken = $this->record->fresh()->reviewToken();
    $oldId = $this->record->fresh()->replacement_event_id;
    $this->proposal['replacement']['items'][0]['quantity'] = 2;
    proposeReplacement($this);
    expect($this->record->fresh()->review_history[0]['replacement_event_id'])->toBe($oldId);
    ($this->sendPacket)([...$this->packet, 'event_id' => $oldId, 'payload' => $this->record->fresh()->review_history[0]['replacement_payload']])->assertConflict();
    Sanctum::actingAs($this->manager);
    $review = ['decision' => 'approved', 'note' => 'Checked correction evidence', 'confirmed_replacement' => true, 'review_token' => $oldToken];
    $this->patchJson('/api/v1/field-sync-recoveries/'.$this->record->id.'/review', $review)->assertConflict();
    $this->patchJson('/api/v1/field-sync-recoveries/'.$this->record->id.'/review', [...$review, 'review_token' => $this->record->fresh()->reviewToken(), 'confirmed_replacement' => false])->assertUnprocessable();
    approveReplacement($this);
    $changed = $this->packet;
    $changed['payload']['items'][0]['quantity'] = 2;
    ($this->sendPacket)($changed)->assertConflict();
    ($this->sendPacket)($this->packet)->assertSuccessful();
});

it('does not allow replacements to change customer, clock, price or stock ownership', function () {
    $url = '/api/v1/field-sync-recoveries/'.$this->record->operation_id.'/request';
    foreach ([['target' => ['visit_id' => 999]], ['captured_at' => now()->toIso8601String()], ['unit_value' => 1]] as $extra) {
        $this->postJson($url, [...$this->proposal, 'replacement' => [...$this->proposal['replacement'], ...$extra]])->assertUnprocessable();
    }
    $this->postJson($url, [...$this->proposal, 'replacement' => [...$this->proposal['replacement'], 'sample_issue_id' => 999999]])->assertUnprocessable();
    Sanctum::actingAs(reportingUser('UnrelatedReplacement'));
    $this->postJson($url, $this->proposal)->assertNotFound();
    $this->getJson('/api/v1/field-sync-recoveries')->assertJsonCount(0, 'data');
});

it('rechecks server changes and finalized calls and refuses to replace a submitted sample distribution', function () {
    proposeReplacement($this);
    approveReplacement($this);
    $this->visit->update(['end_time' => now()]);
    ($this->sendPacket)($this->packet)->assertConflict();
    $this->postJson('/api/v1/field-sync-recoveries/'.$this->record->operation_id.'/request', $this->proposal)->assertUnprocessable();
    $this->visit->update(['end_time' => null]);
    $valid = [...$this->packet, 'event_id' => (string) Str::uuid(), 'payload' => ['expected_version' => null, 'items' => [['item_master_id' => $this->item->id, 'quantity' => 1]]]];
    ($this->sendPacket)($valid)->assertSuccessful();
    $this->postJson('/api/v1/field-sync-recoveries/'.$this->record->operation_id.'/request', $this->proposal)->assertUnprocessable();
    expect((float) $this->stock->fresh()->quantity_reserved)->toBe(1.0);
});

it('expires replacement authority and allows reviewed late posting and closing-window renewal', function () {
    $this->travel(2)->days();
    proposeReplacement($this);
    approveReplacement($this);
    $this->travel(8)->days();
    ($this->sendPacket)($this->packet)->assertConflict();
    proposeReplacement($this);
    approveReplacement($this);
    $this->manager->revokePermissionTo('Review:FieldSyncRecovery');
    ($this->sendPacket)($this->packet)->assertConflict();
    $this->manager->givePermissionTo('Review:FieldSyncRecovery');
    ($this->sendPacket)($this->packet)->assertSuccessful();
    $record = $this->record->fresh();
    $this->getJson('/api/v1/dcrs/'.$this->dcr->id)->assertJsonPath('data.recovery_submission_allowed', true);
    $this->travel(8)->days();
    $this->postJson('/api/v1/field-sync-recoveries/'.$record->operation_id.'/request', [
        'reviewer_id' => $this->manager->id, 'reason' => 'Renew time to close the corrected DCR',
    ])->assertSuccessful();
    approveReplacement($this);
    expect($record->fresh()->replacement_event_id)->toBe($record->replacement_event_id)->and($record->fresh()->status)->toBe('recovered');
    ($this->sendPacket)($this->packet)->assertSuccessful()->assertHeader('Idempotency-Replayed', 'true');
    expect(FieldVisitEvent::query()->count())->toBe(2);
});

it('changes reviewed order fields using the amendment clock while retaining the original call time', function () {
    $contact = ContactDetail::forceCreate(['first_name' => 'Replacement contact', 'created_by' => $this->rep->id, 'updated_by' => $this->rep->id]);
    $this->company->contactDetails()->attach($contact->id);
    $packet = [...$this->packet, 'event_id' => (string) Str::uuid(), 'action' => 'order', 'payload' => [
        'order_id' => (string) Str::uuid(), 'changes' => [['field' => 'line:'.$this->item->id, 'value' => 2, 'updated_at' => $this->packet['captured_at']]],
    ]];
    ($this->sendPacket)($packet)->assertSuccessful();
    $state = FieldOrderState::query()->sole();
    $state->salesOrder->update(['description' => 'More recent ERP note']);
    $failed = [...$packet, 'event_id' => (string) Str::uuid(), 'previous_event_id' => $packet['event_id']];
    $failed['captured_at'] = now()->subMinutes(5)->toIso8601String();
    $failed['payload']['changes'][0]['updated_at'] = $failed['captured_at'];
    $failed['payload']['changes'][0]['value'] = -1;
    ($this->sendPacket)($failed)->assertUnprocessable();
    $this->record = FieldSyncRecovery::query()->where('operation_id', $failed['event_id'])->sole();
    $this->proposal['replacement'] = ['items' => [['item_master_id' => $this->item->id, 'quantity' => 4]],
        'change_description' => true, 'description' => 'Reviewed customer correction'];
    proposeReplacement($this);
    approveReplacement($this);
    ($this->sendPacket)($failed)->assertSuccessful();
    expect((float) $state->salesOrder->fresh()->items()->sole()->quantity)->toBe(4.0)
        ->and((float) $state->salesOrder->fresh()->total)->toBe(40.0)->and($state->salesOrder->fresh()->description)->toBe('Reviewed customer correction')
        ->and($state->fresh()->clocks['description']['event_id'])->toBe($this->record->fresh()->replacement_event_id)
        ->and(FieldVisitEvent::query()->where('event_id', $this->record->fresh()->replacement_event_id)->sole()->captured_at->equalTo($this->record->captured_at))->toBeTrue();
});

it('creates a replacement proposal through the ERP action without posting inventory', function () {
    $this->actingAs($this->rep);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Repeater::configureUsing(fn (Repeater $repeater) => $repeater->generateUuidUsing(false), fn () => Livewire::test(ManageFieldSyncRecoveries::class)->callAction(TestAction::make('replacement')->table($this->record), [
        'reviewer_id' => $this->manager->id, 'reason' => 'Physical distribution verified with the clinic', 'confirmed_replacement' => true,
        'items' => [['item_master_id' => $this->item->id, 'quantity' => 3]], 'sample_issue_id' => 0,
    ])->assertHasNoActionErrors());
    expect($this->record->fresh()->status)->toBe('pending_review')->and($this->record->fresh()->resolution_mode)->toBe('replacement')
        ->and(SgipDistribution::query()->count())->toBe(0);
});

it('uses an explicitly selected HQ source instead of retaining a draft previous allocation', function () {
    $otherLocation = reportingLocation('Previous allocation warehouse');
    $request = SampleRequest::factory()->create(['document_number' => 'REPLACEMENT-REQUEST',
        'employee_id' => $this->rep->employee?->id ?? $this->rep->employee_id, 'territory_id' => $this->visit->territory_id,
        'location_master_id' => $otherLocation->id, 'request_date' => today()]);
    $issue = SampleIssue::withoutEvents(fn () => SampleIssue::query()->create([
        'document_number' => 'REPLACEMENT-ISSUE', 'sample_request_id' => $request->id,
        'from_location_id' => $this->stock->location_master_id, 'to_location_id' => $otherLocation->id, 'issue_date' => today(), 'status' => 'draft',
    ]));
    app(FieldSgipService::class)->upsert($this->rep, $this->visit, [
        'items' => [['item_master_id' => $this->item->id, 'quantity' => 1]], 'sample_issue_id' => $issue->id,
    ]);
    proposeReplacement($this);
    expect($this->record->fresh()->replacement_payload['expected_version'])->not->toBeNull();
    approveReplacement($this);
    ($this->sendPacket)($this->packet)->assertSuccessful();
    $distribution = SgipDistribution::query()->sole();
    expect($distribution->sample_issue_id)->toBeNull()->and($distribution->inventory_source_location_id)->toBe($this->stock->location_master_id)
        ->and((float) $this->stock->fresh()->quantity_reserved)->toBe(3.0);
});

it('rechecks sample compliance after replacement approval without leaving a partial posting', function () {
    proposeReplacement($this);
    approveReplacement($this);
    $this->item->update(['category_type' => 'sample']);
    SgipLimit::query()->create(['applies_to' => 'global', 'item_type' => 'sample', 'period' => 'daily', 'max_quantity' => 2,
        'created_by' => $this->rep->id, 'updated_by' => $this->rep->id]);
    ($this->sendPacket)($this->packet)->assertUnprocessable()->assertJsonValidationErrors('sgip');
    expect(FieldVisitEvent::query()->count())->toBe(0)->and(SgipDistribution::query()->count())->toBe(0)
        ->and((float) $this->stock->fresh()->quantity_reserved)->toBe(0.0);
});

it('rejects a missing original order identity as validation rather than creating another order', function () {
    $packet = [...$this->packet, 'event_id' => (string) Str::uuid(), 'action' => 'order', 'payload' => []];
    ($this->sendPacket)($packet)->assertUnprocessable();
    $this->postJson('/api/v1/field-sync-recoveries/'.$packet['event_id'].'/request', $this->proposal)->assertUnprocessable();
    expect(FieldOrderState::query()->count())->toBe(0);
});
