<?php

use App\Enums\SgipStockSource;
use App\Filament\Resources\FieldSyncRecoveries\Pages\ManageFieldSyncRecoveries;
use App\Models\ContactDetail;
use App\Models\FieldOrderState;
use App\Models\FieldSyncRecovery;
use App\Models\FieldVisitEvent;
use App\Models\InventoryStock;
use App\Models\SalesDcr;
use App\Models\SalesOrder;
use App\Models\SgipDistribution;
use App\Models\Visit;
use App\Models\VisitPreference;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(now()->setTime(15, 0));
    $this->rep = reportingUser('ConflictRep');
    $this->manager = reportingUser('ConflictManager');
    Permission::findOrCreate('Review:FieldSyncRecovery', 'web');
    $this->manager->givePermissionTo('Review:FieldSyncRecovery');
    Sanctum::actingAs($this->rep);
    VisitPreference::current()->update(['allow_backdated_dcr' => false, 'enforce_geo_fencing' => false, 'field_rules' => []]);
    $territory = reportingTerritory('Conflict territory');
    $patch = reportingPatch($territory, 'Conflict patch');
    $this->dcr = SalesDcr::query()->create(['user_id' => $this->rep->id, 'dcr_date' => today(), 'approval_status' => 'draft']);
    $this->visit = Visit::query()->create(['document_number' => 'CONFLICT-CALL', 'employee_id' => $this->rep->id,
        'visit_date' => today(), 'visit_status' => 'started', 'start_time' => now()->subHour(),
        'sales_dcr_id' => $this->dcr->id, 'territory_id' => $territory->id, 'patch_id' => $patch->id]);
    $item = reportingSampleItem('CONFLICT-SAMPLE', 'Conflict sample');
    $this->packet = ['event_id' => (string) Str::uuid(), 'target' => ['visit_id' => $this->visit->id],
        'action' => 'samples', 'captured_at' => now()->subMinutes(20)->toIso8601String(),
        'payload' => ['expected_version' => str_repeat('a', 64), 'items' => [['item_master_id' => $item->id, 'quantity' => 2]]]];
    $this->sendPacket = fn (array $packet) => $this->postJson('/api/v1/field-visit-events', $packet, ['Idempotency-Key' => $packet['event_id']]);
    ($this->sendPacket)($this->packet)->assertConflict();
    $this->record = FieldSyncRecovery::query()->where('operation_id', $this->packet['event_id'])->sole();
    $this->proposal = ['reviewer_id' => $this->manager->id, 'resolution_mode' => 'keep_server',
        'confirmed_no_pending_obligation' => true, 'reason' => 'Checked physical stock: this stale sample entry was not distributed.'];
    $this->decision = ['decision' => 'approved', 'confirmed_no_pending_obligation' => true,
        'note' => 'Verified physical balance and agreed to retain the current server record.'];
});

function proposeKeepServer($test): void
{
    $test->postJson('/api/v1/field-sync-recoveries/'.$test->record->operation_id.'/request', $test->proposal)->assertSuccessful();
    $test->decision['review_token'] = $test->record->fresh()->reviewToken();
}

function approveKeepServer($test): void
{
    Sanctum::actingAs($test->manager);
    $test->patchJson('/api/v1/field-sync-recoveries/'.$test->record->id.'/review', $test->decision)->assertSuccessful();
    Sanctum::actingAs($test->rep);
}

it('keeps server stock unchanged, preserves evidence and acknowledges the chain exactly once', function () {
    proposeKeepServer($this);
    $this->getJson('/api/v1/dcrs/'.$this->dcr->id)->assertJsonPath('data.recovery_review_pending', true);
    ($this->sendPacket)($this->packet)->assertConflict();
    approveKeepServer($this);
    $changed = $this->packet;
    $changed['payload']['items'][0]['quantity'] = 1;
    ($this->sendPacket)($changed)->assertConflict();
    ($this->sendPacket)($this->packet)->assertSuccessful()->assertJsonPath('sync_resolution.kind', 'keep_server');
    ($this->sendPacket)($this->packet)->assertSuccessful()->assertHeader('Idempotency-Replayed', 'true');
    expect(SgipDistribution::query()->count())->toBe(0)->and(FieldVisitEvent::query()->count())->toBe(1);
    $event = FieldVisitEvent::query()->sole();
    expect($event->action)->toBe('reconciled')->and($event->payload['original_payload'])->toEqual($this->packet['payload'])
        ->and($this->record->fresh()->snapshot['request'])->toEqual($this->packet)->and($this->record->fresh()->status)->toBe('recovered');
    $next = ['event_id' => (string) Str::uuid(), 'target' => $this->packet['target'], 'action' => 'details',
        'captured_at' => now()->subMinutes(10)->toIso8601String(), 'previous_event_id' => $this->record->operation_id, 'payload' => ['remarks' => 'The following captured note']];
    ($this->sendPacket)($next)->assertSuccessful();
    expect(FieldVisitEvent::query()->count())->toBe(2)->and($this->visit->fresh()->remarks)->toContain('following captured note');
});

it('requires independent confirmation, scopes evidence and denies self or stale reviewer authority', function () {
    $url = '/api/v1/field-sync-recoveries/'.$this->record->operation_id.'/request';
    $this->postJson($url, [...$this->proposal, 'confirmed_no_pending_obligation' => false])->assertUnprocessable();
    $this->rep->givePermissionTo('Review:FieldSyncRecovery');
    $this->postJson($url, [...$this->proposal, 'reviewer_id' => $this->rep->id])->assertUnprocessable();
    proposeKeepServer($this);
    Sanctum::actingAs(reportingUser('UnrelatedConflictRep'));
    $this->getJson('/api/v1/field-sync-recoveries')->assertJsonCount(0, 'data');
    $this->postJson($url, $this->proposal)->assertNotFound();
    Sanctum::actingAs($this->manager);
    $this->patchJson('/api/v1/field-sync-recoveries/'.$this->record->id.'/review', [...$this->decision, 'confirmed_no_pending_obligation' => false])->assertUnprocessable();
    approveKeepServer($this);
    $this->manager->revokePermissionTo('Review:FieldSyncRecovery');
    ($this->sendPacket)($this->packet)->assertConflict();
    expect(FieldVisitEvent::query()->count())->toBe(0);
});

it('rejects changed server state at review and retry and retains replaced decisions', function () {
    proposeKeepServer($this);
    $this->visit->update(['end_time' => now()]);
    Sanctum::actingAs($this->manager);
    $this->patchJson('/api/v1/field-sync-recoveries/'.$this->record->id.'/review', $this->decision)->assertConflict();
    Sanctum::actingAs($this->rep);
    proposeKeepServer($this);
    approveKeepServer($this);
    $this->visit->update(['end_time' => now()->addSecond()]);
    ($this->sendPacket)($this->packet)->assertConflict();
    proposeKeepServer($this);
    expect($this->record->fresh()->review_history)->toHaveCount(2);
    approveKeepServer($this);
    ($this->sendPacket)($this->packet)->assertSuccessful();
});

it('does not resolve into locked DCRs and cannot convert check-in evidence to a disposition', function () {
    proposeKeepServer($this);
    approveKeepServer($this);
    $this->dcr->update(['approval_status' => 'approved']);
    ($this->sendPacket)($this->packet)->assertUnprocessable();
    expect(FieldVisitEvent::query()->count())->toBe(0);
    $other = [...$this->packet, 'event_id' => (string) Str::uuid(), 'action' => 'check_in', 'payload' => []];
    ($this->sendPacket)($other)->assertUnprocessable();
    $this->postJson('/api/v1/field-sync-recoveries/'.$other['event_id'].'/request', $this->proposal)->assertConflict();
});

it('expires keep-server authority, supports re-review and never resumes rejected changes', function () {
    proposeKeepServer($this);
    approveKeepServer($this);
    $this->travel(8)->days();
    ($this->sendPacket)($this->packet)->assertConflict();
    proposeKeepServer($this);
    Sanctum::actingAs($this->manager);
    $this->patchJson('/api/v1/field-sync-recoveries/'.$this->record->id.'/review', [...$this->decision, 'decision' => 'rejected', 'note' => 'Need further evidence of physical stock reconciliation'])->assertSuccessful();
    Sanctum::actingAs($this->rep);
    ($this->sendPacket)($this->packet)->assertConflict();
    proposeKeepServer($this);
    approveKeepServer($this);
    ($this->sendPacket)($this->packet)->assertSuccessful();
    $this->getJson('/api/v1/dcrs/'.$this->dcr->id)->assertJsonPath('data.recovery_submission_allowed', true);
    $this->travel(8)->days();
    $this->postJson('/api/v1/field-sync-recoveries/'.$this->record->operation_id.'/request', [
        'reviewer_id' => $this->manager->id, 'reason' => 'Renew time to close the reconciled DCR',
    ])->assertSuccessful();
    approveKeepServer($this);
    expect($this->record->fresh()->status)->toBe('recovered')->and($this->record->fresh()->resolution_mode)->toBe('keep_server');
    ($this->sendPacket)($this->packet)->assertSuccessful()->assertHeader('Idempotency-Replayed', 'true');
    expect(FieldVisitEvent::query()->count())->toBe(1);
});

it('can reconcile missing sample masters without recreating or posting them', function () {
    $packet = [...$this->packet, 'event_id' => (string) Str::uuid(), 'payload' => ['expected_version' => null, 'items' => [['item_master_id' => 99999999, 'quantity' => 2]]]];
    ($this->sendPacket)($packet)->assertUnprocessable();
    $this->record = FieldSyncRecovery::query()->where('operation_id', $packet['event_id'])->sole();
    proposeKeepServer($this);
    approveKeepServer($this);
    ($this->sendPacket)($packet)->assertSuccessful()->assertJsonPath('sync_resolution.kind', 'keep_server');
    expect(SgipDistribution::query()->count())->toBe(0);
});

it('renders keep-server evidence and review actions in ERP', function () {
    proposeKeepServer($this);
    $this->actingAs($this->manager);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(ManageFieldSyncRecoveries::class)->assertSuccessful()->assertCanSeeTableRecords([$this->record]);
});

it('requires the manager to review the same proposal that was displayed', function () {
    proposeKeepServer($this);
    $oldDecision = $this->decision;
    $this->proposal['reason'] = 'Revised evidence after discussing physical samples with the customer';
    proposeKeepServer($this);
    Sanctum::actingAs($this->manager);
    $this->patchJson('/api/v1/field-sync-recoveries/'.$this->record->id.'/review', $oldDecision)->assertConflict();
    approveKeepServer($this);
    expect($this->record->fresh()->status)->toBe('approved');
});

it('retains an existing commercial order and rejects a review if its lines change', function () {
    $company = reportingDoctor($this->rep, 'Conflict order customer');
    $contact = ContactDetail::forceCreate(['first_name' => 'Order Contact', 'created_by' => $this->rep->id, 'updated_by' => $this->rep->id]);
    $company->contactDetails()->attach($contact->id);
    $this->visit->visitables()->create(['visitable_type' => $company::class, 'visitable_id' => $company->id]);
    $item = reportingSampleItem('CONFLICT-ORDER', 'Ordered product');
    $item->update(['selling_price' => 10]);
    $packet = [...$this->packet, 'event_id' => (string) Str::uuid(), 'action' => 'order',
        'payload' => ['order_id' => (string) Str::uuid(), 'changes' => [
            ['field' => 'line:'.$item->id, 'value' => 2, 'updated_at' => $this->packet['captured_at']],
        ]]];
    ($this->sendPacket)($packet)->assertSuccessful();
    $state = FieldOrderState::query()->sole();
    $order = $state->salesOrder;
    $order->forceFill(['tax' => 5])->save();
    $failed = [...$packet, 'event_id' => (string) Str::uuid(), 'previous_event_id' => $packet['event_id']];
    $failed['payload']['changes'][0]['value'] = 5;
    ($this->sendPacket)($failed)->assertConflict();
    $this->record = FieldSyncRecovery::query()->where('operation_id', $failed['event_id'])->sole();
    proposeKeepServer($this);
    approveKeepServer($this);
    $line = $order->items()->sole();
    $line->forceFill(['quantity' => 3])->save();
    ($this->sendPacket)($failed)->assertConflict();
    proposeKeepServer($this);
    approveKeepServer($this);
    ($this->sendPacket)($failed)->assertSuccessful()->assertJsonPath('sync_resolution.kind', 'keep_server');
    expect((float) $line->fresh()->quantity)->toBe(3.0)->and((float) $order->fresh()->tax)->toBe(5.0)
        ->and(SalesOrder::query()->count())->toBe(1)->and($state->fresh()->clocks)->toEqual($state->clocks);
});

it('preserves the current sample distribution and its real stock reservation', function () {
    $company = reportingDoctor($this->rep, 'Sample recipient');
    $this->visit->visitables()->create(['visitable_type' => $company::class, 'visitable_id' => $company->id]);
    $item = reportingSampleItem('RESOLVED-STOCK', 'Reserved sample');
    $location = reportingLocation('Resolution HQ');
    VisitPreference::current()->update(['sgip_stock_source' => SgipStockSource::Headquarters, 'sgip_hq_location_id' => $location->id]);
    $stock = InventoryStock::query()->create(['item_master_id' => $item->id, 'location_master_id' => $location->id,
        'quantity_on_hand' => 5, 'quantity_reserved' => 0, 'quantity_available' => 5]);
    $packet = [...$this->packet, 'event_id' => (string) Str::uuid(),
        'payload' => ['expected_version' => null, 'items' => [['item_master_id' => $item->id, 'quantity' => 2]]]];
    ($this->sendPacket)($packet)->assertSuccessful();
    $distribution = SgipDistribution::query()->sole();
    $version = $distribution->fieldVersion();
    proposeKeepServer($this);
    approveKeepServer($this);
    ($this->sendPacket)($this->packet)->assertSuccessful();
    expect(SgipDistribution::query()->count())->toBe(1)->and($distribution->fresh()->fieldVersion())->toBe($version)
        ->and((float) $stock->fresh()->quantity_reserved)->toBe(2.0)->and((float) $stock->fresh()->quantity_on_hand)->toBe(5.0);
});

it('rejects malformed original call evidence without opening a review', function () {
    $packet = [...$this->packet, 'event_id' => (string) Str::uuid(), 'target' => 'not a call target'];
    ($this->sendPacket)($packet)->assertUnprocessable();
    $this->postJson('/api/v1/field-sync-recoveries/'.$packet['event_id'].'/request', $this->proposal)->assertUnprocessable();
    expect(FieldSyncRecovery::query()->where('operation_id', $packet['event_id'])->sole()->status)->toBe('failed');
});
