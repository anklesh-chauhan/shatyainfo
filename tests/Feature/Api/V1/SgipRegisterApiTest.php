<?php

use App\Enums\SgipStockSource;
use App\Filament\Resources\SgipDistributions\Pages\CreateSgipDistribution;
use App\Filament\Resources\SgipDistributions\Pages\EditSgipDistribution;
use App\Models\AccountMaster;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\MarketingCampaign;
use App\Models\SampleIssue;
use App\Models\SampleRequest;
use App\Models\SgipDistribution;
use App\Models\SgipLimit;
use App\Models\Visit;
use App\Models\VisitPreference;
use App\Services\Inventory\InventoryService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actor = reportingUser('SGIP reviewer');
    $this->outside = reportingUser('Other SGIP user');
    $permissions = [];
    foreach (['ViewAny', 'View', 'Create', 'Update', 'Delete', 'DeleteAny', 'Restore', 'RestoreAny', 'ForceDelete', 'ForceDeleteAny'] as $ability) {
        $permissions[] = $ability.':SgipDistribution';
    }
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $this->actor->givePermissionTo($permissions);
    $this->outside->givePermissionTo($permissions);
    Sanctum::actingAs($this->actor);
    $this->doctor = reportingDoctor($this->actor, 'SGIP doctor');
    $this->item = reportingSampleItem('SGREG', 'Trial pack');
    $this->location = reportingLocation('SGIP warehouse');
    $this->territory = reportingTerritory('SGIP territory');
    VisitPreference::current()->update(['sgip_stock_source' => SgipStockSource::Headquarters, 'sgip_hq_location_id' => $this->location->id]);
    $this->stock = InventoryStock::query()->create([
        'item_master_id' => $this->item->id, 'location_master_id' => $this->location->id,
        'quantity_on_hand' => 10, 'quantity_reserved' => 0, 'quantity_available' => 10,
    ]);
    $this->path = '/api/v1/sgip-distributions';
    $this->payload = [
        'account_master_id' => $this->doctor->id, 'territory_id' => $this->territory->id, 'visit_date' => today()->toDateString(),
        'items' => [['item_master_id' => $this->item->id, 'quantity' => 3, 'unit_value' => '2.50']],
    ];
});

it('negotiates SGIP review without visit permissions and scopes register and choices', function () {
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertOk()
        ->assertJsonFragment(['key' => 'g06-07', 'standalone_screen_key' => 'sgip-register']);
    $this->getJson($this->path.'/options/doctors')->assertOk()->assertJsonPath('data.0.id', $this->doctor->id);
    $headers = ['Idempotency-Key' => (string) Str::uuid()];
    $id = $this->postJson($this->path, $this->payload, $headers)->assertCreated()->json('data.id');
    $this->postJson($this->path, $this->payload, $headers)->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $id);
    $this->getJson($this->path.'?search=SGIP&status=draft&date_from='.today()->toDateString())->assertOk()->assertJsonCount(1, 'data');
    $this->getJson($this->path.'?date_to='.today()->toDateString())->assertOk()->assertJsonCount(1, 'data');
    expect(SgipDistribution::findOrFail($id)->employee_id)->toBe($this->actor->employee->id);
    Sanctum::actingAs($this->outside);
    $this->getJson($this->path)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($this->path.'/'.$id)->assertNotFound();
    $this->getJson($this->path.'/options/doctors')->assertJsonCount(0, 'data');
    $this->postJson($this->path, $this->payload)->assertUnprocessable();
});

it('saves drafts by revision and validates quantities and selections', function () {
    $id = $this->postJson($this->path, $this->payload)->assertCreated()->json('data.id');
    $path = $this->path.'/'.$id;
    $draft = $this->getJson($path)->assertJsonPath('data.total_value', '7.50')->json('data');
    $changed = [...$this->payload, 'items' => [[...$this->payload['items'][0], 'quantity' => 4]], 'revision' => $draft['revision']];
    $this->patchJson($path, $changed)->assertOk()->assertJsonPath('data.total_value', '10.00');
    $this->patchJson($path, $changed)->assertConflict();
    $this->postJson($path.'/submit', ['revision' => $draft['revision']])->assertConflict();
    $this->postJson($this->path, [...$this->payload, 'items' => [[...$this->payload['items'][0], 'quantity' => 1.5]]])->assertUnprocessable();
    $this->postJson($this->path, [...$this->payload, 'items' => [[...$this->payload['items'][0], 'item_master_id' => 999999]]])->assertUnprocessable();
    $empty = $this->postJson($this->path, [...$this->payload, 'items' => []])->assertCreated()->json('data.id');
    $emptyPath = $this->path.'/'.$empty;
    $this->postJson($emptyPath.'/submit', ['revision' => $this->getJson($emptyPath)->json('data.revision')])->assertUnprocessable()->assertJsonValidationErrors('items');
});

it('reserves on submit releases on return and deducts exactly once on posting', function () {
    $id = $this->postJson($this->path, $this->payload)->assertCreated()->json('data.id');
    $path = $this->path.'/'.$id;
    $this->postJson($path.'/submit', ['revision' => $this->getJson($path)->json('data.revision')])->assertOk();
    expect((float) $this->stock->refresh()->quantity_reserved)->toBe(3.0);
    $submitted = $this->getJson($path)->assertJsonPath('data.can_edit', false)->json('data');
    $this->postJson($path.'/delete', ['revision' => $submitted['revision']])->assertForbidden();
    $this->postJson($path.'/return', ['revision' => $submitted['revision']])->assertOk();
    expect((float) $this->stock->refresh()->quantity_reserved)->toBe(0.0);
    $this->postJson($path.'/submit', ['revision' => $this->getJson($path)->json('data.revision')])->assertOk();
    $stale = SgipDistribution::findOrFail($id);
    $this->postJson($path.'/approve', ['revision' => $this->getJson($path)->json('data.revision')])->assertOk();
    app(InventoryService::class)->postSgipDistribution($stale);
    $posted = $this->getJson($path)->assertJsonPath('data.actions', [])->assertJsonPath('data.can_edit', false)->json('data');
    expect((float) $this->stock->refresh()->quantity_on_hand)->toBe(7.0)
        ->and((float) $this->stock->quantity_reserved)->toBe(0.0)
        ->and(InventoryMovement::query()->where('movement_type', 'sgip_distribution')->count())->toBe(1);
    $this->postJson($path.'/approve', ['revision' => $posted['revision']])->assertConflict();
    $this->postJson($path.'/force-delete', ['revision' => $posted['revision']])->assertForbidden();
});

it('rolls back partial reservations and preserves submitted stock when posting fails', function () {
    $second = reportingSampleItem('SGZERO', 'Unavailable pack');
    $payload = [...$this->payload, 'items' => [...$this->payload['items'], ['item_master_id' => $second->id, 'quantity' => 1, 'unit_value' => 1]]];
    $id = $this->postJson($this->path, $payload)->assertCreated()->json('data.id');
    $path = $this->path.'/'.$id;
    $this->postJson($path.'/submit', ['revision' => $this->getJson($path)->json('data.revision')])->assertConflict();
    expect((float) $this->stock->refresh()->quantity_reserved)->toBe(0.0);
    $revision = $this->getJson($path)->json('data.revision');
    $this->patchJson($path, [...$this->payload, 'revision' => $revision])->assertOk();
    $this->postJson($path.'/submit', ['revision' => $this->getJson($path)->json('data.revision')])->assertOk();
    $newSource = reportingLocation('New configured warehouse');
    VisitPreference::current()->update(['sgip_hq_location_id' => $newSource->id]);
    $this->postJson($path.'/approve', ['revision' => $this->getJson($path)->json('data.revision')])->assertUnprocessable()->assertJsonValidationErrors('stock');
    expect((float) $this->stock->refresh()->quantity_reserved)->toBe(3.0)
        ->and(InventoryMovement::query()->count())->toBe(0);
    $this->postJson($path.'/return', ['revision' => $this->getJson($path)->json('data.revision')])->assertOk();
    expect((float) $this->stock->refresh()->quantity_reserved)->toBe(0.0);
});

it('checks SGIP compliance before reserving and aggregates repeated campaign items', function () {
    SgipLimit::query()->create(['applies_to' => 'global', 'item_type' => 'sample', 'period' => 'daily', 'max_quantity' => 2]);
    $id = $this->postJson($this->path, $this->payload)->assertCreated()->json('data.id');
    $path = $this->path.'/'.$id;
    $this->getJson($path)->assertJsonCount(1, 'data.violations');
    $this->postJson($path.'/submit', ['revision' => $this->getJson($path)->json('data.revision')])->assertUnprocessable();
    expect((float) $this->stock->refresh()->quantity_reserved)->toBe(0.0);
    SgipLimit::query()->delete();
    $campaign = MarketingCampaign::factory()->create(['status' => 'active', 'start_date' => today()->subDay(), 'end_date' => today()->addDay()]);
    $quota = $campaign->territoryQuotas()->create(['territory_id' => $this->territory->id, 'item_master_id' => $this->item->id, 'quota_quantity' => 5, 'used_quantity' => 0]);
    $payload = [...$this->payload, 'marketing_campaign_id' => $campaign->id, 'items' => [$this->payload['items'][0], $this->payload['items'][0]]];
    $id = $this->postJson($this->path, $payload)->assertCreated()->json('data.id');
    $path = $this->path.'/'.$id;
    $this->postJson($path.'/submit', ['revision' => $this->getJson($path)->json('data.revision')])->assertUnprocessable();
    $quota->update(['quota_quantity' => 6]);
    $this->postJson($path.'/submit', ['revision' => $this->getJson($path)->json('data.revision')])->assertOk();
    $this->postJson($path.'/approve', ['revision' => $this->getJson($path)->json('data.revision')])->assertOk();
    expect((float) $quota->refresh()->used_quantity)->toBe(6.0);
});

it('requires independent record bulk and update permissions for SGIP actions', function () {
    $id = $this->postJson($this->path, $this->payload)->assertCreated()->json('data.id');
    $path = $this->path.'/'.$id;
    $this->actor->revokePermissionTo('Update:SgipDistribution');
    $this->postJson($path.'/submit', ['revision' => $this->getJson($path)->json('data.revision')])->assertForbidden();
    $this->actor->revokePermissionTo('DeleteAny:SgipDistribution');
    $this->postJson($this->path.'/records/delete', ['ids' => [$id]])->assertForbidden();
    $this->postJson($path.'/delete', ['revision' => $this->getJson($path)->json('data.revision')])->assertOk();
    $this->getJson($this->path.'?trash=trashed')->assertJsonPath('data.0.id', $id);
    $this->postJson($this->path.'/records/restore', ['ids' => [$id]])->assertOk();
    $this->postJson($path.'/delete', ['revision' => $this->getJson($path)->json('data.revision')])->assertOk();
    $this->postJson($path.'/force-delete', ['revision' => $this->getJson($path)->json('data.revision')])->assertOk();
    $this->getJson($path)->assertNotFound();
    $this->actor->revokePermissionTo('ViewAny:SgipDistribution');
    $this->getJson($this->path)->assertForbidden();
});

it('shares Admin draft saves submission reservations and posting authorization', function () {
    $this->actingAs($this->actor, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(CreateSgipDistribution::class)->fillForm($this->payload)->call('create')->assertHasNoFormErrors();
    $record = SgipDistribution::query()->sole();
    Livewire::test(EditSgipDistribution::class, ['record' => $record->id])->callAction('submit')->assertNotified('SGIP distribution updated');
    expect((float) $this->stock->refresh()->quantity_reserved)->toBe(3.0);
    $editor = Livewire::test(EditSgipDistribution::class, ['record' => $record->id])->mountAction('approve');
    $this->actor->revokePermissionTo('Update:SgipDistribution');
    $editor->callMountedAction()->assertForbidden();
    expect($record->refresh()->isInventoryPosted())->toBeFalse();
});

it('only selects posted sample issues allocated to the distribution employee', function () {
    $request = SampleRequest::factory()->create(['employee_id' => $this->actor->employee->id, 'location_master_id' => $this->location->id]);
    $issue = SampleIssue::factory()->create([
        'sample_request_id' => $request->id, 'from_location_id' => reportingLocation('Central')->id,
        'to_location_id' => $this->location->id, 'status' => 'posted', 'posted_at' => now(),
    ]);
    $foreignRequest = SampleRequest::factory()->create(['employee_id' => $this->outside->employee->id, 'location_master_id' => $this->location->id]);
    $foreign = SampleIssue::factory()->create([
        'sample_request_id' => $foreignRequest->id, 'from_location_id' => $issue->from_location_id,
        'to_location_id' => $this->location->id, 'status' => 'posted', 'posted_at' => now(),
    ]);
    VisitPreference::current()->update(['sgip_stock_source' => SgipStockSource::SampleIssue]);
    $this->getJson($this->path.'/options/issues')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $issue->id);
    $this->postJson($this->path, [...$this->payload, 'sample_issue_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors('sample_issue_id');
    $id = $this->postJson($this->path, [...$this->payload, 'sample_issue_id' => $issue->id])->assertCreated()->json('data.id');
    $path = $this->path.'/'.$id;
    $this->postJson($path.'/submit', ['revision' => $this->getJson($path)->json('data.revision')])->assertOk();
    $this->postJson($path.'/approve', ['revision' => $this->getJson($path)->json('data.revision')])->assertOk();
    expect((float) $this->stock->refresh()->quantity_on_hand)->toBe(7.0);
});

it('rolls back a mixed bulk deletion and never grants actions through record scope bypass', function () {
    $first = $this->postJson($this->path, $this->payload)->assertCreated()->json('data.id');
    $second = $this->postJson($this->path, $this->payload)->assertCreated()->json('data.id');
    $secondPath = $this->path.'/'.$second;
    $this->postJson($secondPath.'/submit', ['revision' => $this->getJson($secondPath)->json('data.revision')])->assertOk();
    $this->postJson($this->path.'/records/delete', ['ids' => [$first, $second]])->assertForbidden();
    expect(SgipDistribution::find($first))->not->toBeNull();
    Permission::findOrCreate('AccessAllRecords', 'web');
    $this->outside->givePermissionTo('AccessAllRecords')->revokePermissionTo('Update:SgipDistribution');
    Sanctum::actingAs($this->outside);
    $this->getJson($secondPath)->assertOk();
    $this->postJson($secondPath.'/approve', ['revision' => $this->getJson($secondPath)->json('data.revision')])->assertForbidden();
});

it('preserves visit context and rejects stale register reviews after field capture changes', function () {
    $rep = fieldReportingUser('Capture owner');
    $rep->givePermissionTo(['ViewAny:SgipDistribution', 'View:SgipDistribution']);
    Sanctum::actingAs($rep);
    $doctor = reportingDoctor($rep, 'Captured doctor');
    $visit = Visit::withoutEvents(fn () => Visit::query()->create([
        'document_number' => 'SGIP-'.Str::uuid(), 'employee_id' => $rep->id,
        'visit_date' => today(), 'visit_type' => 'unplanned', 'visit_status' => 'draft', 'approval_status' => 'draft',
    ]));
    $visit->visitables()->create(['visitable_type' => AccountMaster::class, 'visitable_id' => $doctor->id]);
    $fieldPath = '/api/v1/visits/'.$visit->id.'/sgip';
    $data = $this->putJson($fieldPath, ['items' => $this->payload['items']])->assertSuccessful()->json('data');
    Permission::findOrCreate('AccessAllRecords', 'web');
    $this->actor->givePermissionTo('AccessAllRecords');
    Sanctum::actingAs($this->actor);
    $path = $this->path.'/'.$data['id'];
    $revision = $this->getJson($path)->assertOk()->json('data.revision');
    Sanctum::actingAs($rep);
    $this->putJson($fieldPath, ['items' => [[...$this->payload['items'][0], 'quantity' => 4]]])->assertSuccessful();
    Sanctum::actingAs($this->actor);
    $this->postJson($path.'/submit', ['revision' => $revision])->assertConflict();
    $current = $this->getJson($path)->json('data');
    $this->patchJson($path, [...$this->payload, 'revision' => $current['revision']])->assertUnprocessable()->assertJsonValidationErrors('account_master_id');
    $this->patchJson($path, [...$this->payload, 'account_master_id' => $doctor->id, 'territory_id' => null,
        'revision' => $current['revision']])->assertOk()->assertJsonPath('data.user_id', $rep->id);
});
