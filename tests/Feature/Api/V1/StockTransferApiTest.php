<?php

use App\Enums\InventoryDocumentStatus;
use App\Filament\Resources\InventoryTransfers\Pages\ManageInventoryTransfers;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalSetting;
use App\Models\InventoryStock;
use App\Models\InventoryTransfer;
use App\Models\JobRole;
use App\Models\Organization;
use App\Models\Position;
use App\Services\Approval\ApprovalService;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\StockTransferRegister;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actor = reportingUser('Transfer operator');
    $this->outside = reportingUser('Other operator');
    foreach (['ViewAny:InventoryTransfer', 'View:InventoryTransfer', 'Create:InventoryTransfer', 'Update:InventoryTransfer', 'Delete:InventoryTransfer'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $this->actor->givePermissionTo($permission);
    }
    Sanctum::actingAs($this->outside);
    $this->privateItem = reportingSampleItem('PRIVATE-TR', 'Private item');
    $this->privateLocation = reportingLocation('Private store');
    $this->privateTransfer = InventoryTransfer::query()->create(['item_master_id' => $this->privateItem->id, 'from_location_master_id' => $this->privateLocation->id,
        'to_location_master_id' => reportingLocation('Other private store')->id, 'quantity' => 1, 'unit_cost' => 1]);
    Sanctum::actingAs($this->actor);
    $this->item = reportingSampleItem('TR-ITEM', 'Transfer item');
    $this->source = reportingLocation('Source store');
    $this->destination = reportingLocation('Target store');
    $this->sourceStock = InventoryStock::query()->create(['item_master_id' => $this->item->id, 'location_master_id' => $this->source->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 2, 'quantity_available' => 8, 'average_cost' => 5]);
    $this->destinationStock = InventoryStock::query()->create(['item_master_id' => $this->item->id, 'location_master_id' => $this->destination->id, 'quantity_on_hand' => 2, 'quantity_available' => 2, 'average_cost' => 4]);
    $this->input = ['item_master_id' => $this->item->id, 'from_location_master_id' => $this->source->id, 'to_location_master_id' => $this->destination->id, 'quantity' => '3.125', 'unit_cost' => '10.1234', 'remarks' => 'Move stock'];
    $this->path = '/api/v1/inventory-transfers';
    $this->transfers = app(StockTransferRegister::class);
});

it('negotiates and scopes transfer reads search choices and direct access', function () {
    $record = $this->transfers->save($this->actor, null, $this->input);
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertJsonFragment(['key' => 'g07-06', 'screen_key' => 'inventory-transfers']);
    $this->getJson($this->path)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $record->id);
    $this->getJson($this->path.'?search=Private')->assertJsonCount(0, 'data');
    $this->getJson($this->path.'?status=posted')->assertJsonCount(0, 'data');
    $this->getJson($this->path.'?from_location_master_id='.$this->privateLocation->id)->assertJsonCount(0, 'data');
    $this->getJson($this->path.'/options/items')->assertJsonCount(1, 'data');
    $this->getJson($this->path.'/options/locations?search=Private')->assertJsonCount(0, 'data');
    $this->getJson($this->path.'/'.$this->privateTransfer->id)->assertNotFound();
    $this->actor->revokePermissionTo('View:InventoryTransfer');
    $this->getJson($this->path)->assertForbidden();
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertJsonMissing(['key' => 'g07-06']);
});

it('creates once validates selected masters and protects stale edits', function () {
    $key = (string) Str::uuid();
    $id = $this->withHeader('Idempotency-Key', $key)->postJson($this->path, $this->input)->assertCreated()->json('data.id');
    $this->withHeader('Idempotency-Key', $key)->postJson($this->path, $this->input)->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $id);
    $this->flushHeaders();
    $detail = $this->getJson($this->path.'/'.$id)->assertJsonPath('data.quantity', '3.125')->assertJsonPath('data.unit_cost', '10.1234')->json('data');
    $input = $this->input + ['revision' => $detail['revision']];
    $input['quantity'] = '4.125';
    $this->patchJson($this->path.'/'.$id, $input)->assertOk();
    $this->patchJson($this->path.'/'.$id, $input)->assertConflict();
    foreach (['item_master_id' => $this->privateItem->id, 'from_location_master_id' => $this->privateLocation->id, 'to_location_master_id' => $this->source->id, 'quantity' => '1.0001', 'unit_cost' => '-1'] as $field => $value) {
        $this->postJson($this->path, array_replace($this->input, [$field => $value]))->assertUnprocessable();
    }
    $this->assertDatabaseCount('inventory_transfers', 2);
    $this->assertDatabaseCount('inventory_movements', 0);
});

it('posts paired movements and both balances exactly once and retains posted documents', function () {
    $record = $this->transfers->save($this->actor, null, $this->input);
    $path = $this->path.'/'.$record->id;
    $body = ['revision' => $this->transfers->revision($record)];
    $key = (string) Str::uuid();
    $this->withHeader('Idempotency-Key', $key)->postJson($path.'/post', $body)->assertOk();
    $this->withHeader('Idempotency-Key', $key)->postJson($path.'/post', $body)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
    $this->flushHeaders();
    app(InventoryService::class)->postTransfer($record->fresh(), $this->actor->id);
    expect($this->sourceStock->refresh()->quantity_on_hand)->toBe('6.875')->and($this->sourceStock->quantity_available)->toBe('4.875')
        ->and($this->destinationStock->refresh()->quantity_on_hand)->toBe('5.125')->and($this->destinationStock->average_cost)->toBe('7.7338');
    $this->getJson($path)->assertJsonPath('data.posted_by', $this->actor->id)->assertJsonPath('data.can_edit', false)->assertJsonCount(2, 'data.movements')->assertJsonPath('data.movements.0.movement_type', 'transfer_out')->assertJsonPath('data.movements.1.movement_type', 'transfer_in');
    $this->postJson($path.'/delete', ['revision' => $this->transfers->revision($record->refresh())])->assertConflict();
    $this->patchJson($path, $this->input + ['revision' => $this->transfers->revision($record)])->assertConflict();
    $this->assertDatabaseCount('inventory_movements', 2);
});

it('rolls back both legs when available stock is insufficient and rechecks current locations and grants', function () {
    $record = $this->transfers->save($this->actor, null, array_replace($this->input, ['quantity' => '9']));
    $path = $this->path.'/'.$record->id;
    $this->postJson($path.'/post', ['revision' => $this->transfers->revision($record)])->assertUnprocessable();
    expect($this->sourceStock->refresh()->quantity_on_hand)->toBe('10.000')->and($this->destinationStock->refresh()->quantity_on_hand)->toBe('2.000');
    $this->assertDatabaseCount('inventory_movements', 0);
    $this->destination->update(['is_active' => false]);
    $this->postJson($path.'/post', ['revision' => $this->transfers->revision($record)])->assertUnprocessable();
    $this->actor->revokePermissionTo('Update:InventoryTransfer');
    $this->postJson($path.'/post', ['revision' => $this->transfers->revision($record)])->assertForbidden();
});

it('enforces the disabled module on direct APIs actions and Admin', function () {
    $record = $this->transfers->save($this->actor, null, $this->input);
    Organization::query()->create(['name' => 'No inventory', 'metadata' => ['company_setup' => ['organization_saved' => true]]]);
    $this->getJson($this->path)->assertForbidden();
    $this->getJson($this->path.'/'.$record->id)->assertForbidden();
    $this->getJson($this->path.'/options/items')->assertForbidden();
    $this->postJson($this->path, $this->input)->assertForbidden();
    $this->postJson($this->path.'/'.$record->id.'/post', ['revision' => $this->transfers->revision($record)])->assertForbidden();
    $this->actingAs($this->actor, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(ManageInventoryTransfers::class)->assertForbidden();
});

it('submits rejects corrects resubmits and posts through configured approval', function () {
    $manager = reportingUser('Transfer approver');
    $role = JobRole::query()->create(['name' => 'Transfer approver', 'code' => 'TRAPP', 'level' => 2]);
    $position = Position::query()->create(['name' => 'Transfer review', 'code' => 'TRPOS', 'job_role_id' => $role->id]);
    $manager->employee->positions()->attach($position->id);
    ApprovalSetting::instance()->update(['enabled_modules' => [InventoryTransfer::class]]);
    $flow = ApprovalFlow::query()->create(['module' => 'InventoryTransfer', 'active' => true]);
    ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'step_order' => 1, 'job_role_id' => $role->id, 'territory_scope' => 'all']);
    $record = $this->transfers->save($this->actor, null, $this->input);
    $path = $this->path.'/'.$record->id;
    $this->postJson($path.'/post', ['revision' => $this->transfers->revision($record)])->assertConflict();
    $this->postJson($path.'/submit', ['revision' => $this->transfers->revision($record)])->assertOk();
    $record->refresh();
    $this->postJson($path.'/delete', ['revision' => $this->transfers->revision($record)])->assertConflict();
    app(ApprovalService::class)->reject($record->approval, $manager->id, 'Review cost');
    $record->refresh();
    $record = $this->transfers->save($this->actor, $record->id, $this->input + ['revision' => $this->transfers->revision($record)]);
    $this->transfers->act($this->actor, $record->id, 'submit', $this->transfers->revision($record));
    $record->refresh();
    app(ApprovalService::class)->approve($record->approval, $manager->id);
    $record->refresh();
    $this->postJson($path.'/post', ['revision' => $this->transfers->revision($record)])->assertOk();
});

it('shares Admin modal saves and posting guards with mobile', function () {
    $this->actingAs($this->actor, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(ManageInventoryTransfers::class)->callAction('create', data: $this->input)->assertHasNoActionErrors();
    $record = InventoryTransfer::query()->where('created_by', $this->actor->id)->firstOrFail();
    Livewire::test(ManageInventoryTransfers::class)->callTableAction('edit', $record, data: array_replace($this->input, ['quantity' => '2', 'revision' => $this->transfers->revision($record)]))->assertHasNoActionErrors();
    expect($record->refresh()->quantity)->toBe('2.000');
    Livewire::test(ManageInventoryTransfers::class)->callTableAction('post', $record)->assertNotified();
    Livewire::test(ManageInventoryTransfers::class)->assertTableActionHidden('edit', $record->refresh())->assertTableActionHidden('delete', $record)->mountTableAction('view', $record)->assertHasNoActionErrors();
});

it('rejects cancelled transfers in the posting service and retains archived labels', function () {
    $record = $this->transfers->save($this->actor, null, $this->input);
    $record->forceFill(['status' => InventoryDocumentStatus::Cancelled])->saveQuietly();
    expect(fn () => app(InventoryService::class)->postTransfer($record, $this->actor->id))->toThrow(RuntimeException::class, 'Only draft transfers can be posted.');
    $this->item->delete();
    $this->destination->delete();
    $this->getJson($this->path.'/'.$record->id)->assertJsonPath('data.item', 'Transfer item')->assertJsonPath('data.destination', 'Target store')->assertJsonCount(0, 'data.actions');
});

it('rechecks the current source balance when two saved drafts compete for the same stock', function () {
    $input = array_replace($this->input, ['quantity' => '6']);
    $first = $this->transfers->save($this->actor, null, $input);
    $second = $this->transfers->save($this->actor, null, $input);
    $this->transfers->act($this->actor, $first->id, 'post', $this->transfers->revision($first));
    $this->postJson($this->path.'/'.$second->id.'/post', ['revision' => $this->transfers->revision($second)])->assertUnprocessable();
    expect($this->sourceStock->refresh()->quantity_on_hand)->toBe('4.000')->and($this->destinationStock->refresh()->quantity_on_hand)->toBe('8.000')->and($second->fresh()->isPosted())->toBeFalse();
    $this->assertDatabaseCount('inventory_movements', 2);
});
