<?php

use App\Enums\InventoryDocumentStatus;
use App\Filament\Resources\InventoryAdjustments\Pages\CreateInventoryAdjustment;
use App\Filament\Resources\InventoryAdjustments\Pages\EditInventoryAdjustment;
use App\Filament\Resources\InventoryAdjustments\Pages\ListInventoryAdjustments;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalSetting;
use App\Models\InventoryAdjustment;
use App\Models\InventoryStock;
use App\Models\JobRole;
use App\Models\Organization;
use App\Models\Position;
use App\Services\Approval\ApprovalService;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\StockAdjustmentRegister;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actor = reportingUser('Adjustment operator');
    $this->outside = reportingUser('Other operator');
    foreach (['ViewAny:InventoryAdjustment', 'View:InventoryAdjustment', 'Create:InventoryAdjustment', 'Update:InventoryAdjustment', 'Delete:InventoryAdjustment', 'DeleteAny:InventoryAdjustment'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $this->actor->givePermissionTo($permission);
    }
    Sanctum::actingAs($this->outside);
    $this->privateItem = reportingSampleItem('PRIVATE-ADJ', 'Private item');
    $this->privateLocation = reportingLocation('Private store');
    $this->privateRecord = InventoryAdjustment::query()->create(['item_master_id' => $this->privateItem->id, 'location_master_id' => $this->privateLocation->id, 'adjustment_type' => 'receipt', 'quantity' => 1, 'reason' => 'Private adjustment']);
    Sanctum::actingAs($this->actor);
    $this->item = reportingSampleItem('ADJ-ITEM', 'Adjustment item');
    $this->location = reportingLocation('Visible store');
    $this->stock = InventoryStock::query()->create(['item_master_id' => $this->item->id, 'location_master_id' => $this->location->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 2, 'quantity_available' => 8, 'average_cost' => 5]);
    $this->input = ['item_master_id' => $this->item->id, 'location_master_id' => $this->location->id, 'adjustment_type' => 'receipt', 'quantity' => '3.125', 'unit_cost' => '10.1234', 'reason' => 'Stock reconciliation', 'remarks' => 'Checked count'];
    $this->path = '/api/v1/inventory-adjustments';
    $this->adjustments = app(StockAdjustmentRegister::class);
});

it('negotiates scoped adjustments search filters and choices', function () {
    $record = $this->adjustments->save($this->actor, null, $this->input);
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertJsonFragment(['key' => 'g07-07', 'screen_key' => 'inventory-adjustments']);
    $this->getJson($this->path)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $record->id);
    $this->getJson($this->path.'?search=Private')->assertJsonCount(0, 'data');
    $this->getJson($this->path.'?adjustment_type=issue')->assertJsonCount(0, 'data');
    $this->getJson($this->path.'?location_master_id='.$this->privateLocation->id)->assertJsonCount(0, 'data');
    $this->getJson($this->path.'/options/items')->assertJsonCount(1, 'data');
    $this->getJson($this->path.'/options/locations?search=Private')->assertJsonCount(0, 'data');
    $this->getJson($this->path.'/'.$this->privateRecord->id)->assertNotFound();
    $this->actor->revokePermissionTo('View:InventoryAdjustment');
    $this->getJson($this->path)->assertForbidden();
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertJsonMissing(['key' => 'g07-07']);
});

it('creates once requires a reason validates precision and rejects stale edits', function () {
    $key = (string) Str::uuid();
    $id = $this->withHeader('Idempotency-Key', $key)->postJson($this->path, $this->input)->assertCreated()->json('data.id');
    $this->withHeader('Idempotency-Key', $key)->postJson($this->path, $this->input)->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $id);
    $this->flushHeaders();
    $detail = $this->getJson($this->path.'/'.$id)->assertJsonPath('data.quantity', '3.125')->assertJsonPath('data.unit_cost', '10.1234')->json('data');
    $input = array_replace($this->input, ['revision' => $detail['revision'], 'unit_cost' => null]);
    $this->patchJson($this->path.'/'.$id, $input)->assertOk()->assertJsonPath('data.unit_cost', null);
    $this->patchJson($this->path.'/'.$id, $input)->assertConflict();
    foreach (['reason' => ' ', 'adjustment_type' => 'other', 'item_master_id' => $this->privateItem->id, 'location_master_id' => $this->privateLocation->id, 'quantity' => '1.0001', 'unit_cost' => '-1'] as $field => $value) {
        $this->postJson($this->path, array_replace($this->input, [$field => $value]))->assertUnprocessable();
    }
    $this->assertDatabaseCount('inventory_adjustments', 2);
});

it('posts all adjustment directions once and retains stock history', function (string $type, string $balance, string $in, string $out) {
    $record = $this->adjustments->save($this->actor, null, array_replace($this->input, ['adjustment_type' => $type]));
    $path = $this->path.'/'.$record->id;
    $body = ['revision' => $this->adjustments->revision($record)];
    $key = (string) Str::uuid();
    $this->withHeader('Idempotency-Key', $key)->postJson($path.'/post', $body)->assertOk();
    $this->withHeader('Idempotency-Key', $key)->postJson($path.'/post', $body)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
    $this->flushHeaders();
    app(InventoryService::class)->postAdjustment($record->fresh(), $this->actor->id);
    expect($this->stock->refresh()->quantity_on_hand)->toBe($balance);
    $this->getJson($path)->assertJsonPath('data.posted_by', $this->actor->id)->assertJsonPath('data.can_edit', false)
        ->assertJsonPath('data.movements.0.movement_type', 'adjustment_'.$type)->assertJsonPath('data.movements.0.quantity_in', $in)->assertJsonPath('data.movements.0.quantity_out', $out);
    $this->postJson($path.'/delete', ['revision' => $this->adjustments->revision($record->refresh())])->assertConflict();
    $this->patchJson($path, $this->input + ['revision' => $this->adjustments->revision($record)])->assertConflict();
    $this->assertDatabaseCount('inventory_movements', 1);
})->with([['receipt', '13.125', '3.125', '0.000'], ['increase', '13.125', '3.125', '0.000'], ['issue', '6.875', '0.000', '3.125'], ['decrease', '6.875', '0.000', '3.125']]);

it('uses current stock cost when omitted and rejects reductions against reserved stock', function () {
    $record = $this->adjustments->save($this->actor, null, array_replace($this->input, ['adjustment_type' => 'issue', 'quantity' => 9, 'unit_cost' => null]));
    $this->postJson($this->path.'/'.$record->id.'/post', ['revision' => $this->adjustments->revision($record)])->assertUnprocessable();
    expect($this->stock->refresh()->quantity_on_hand)->toBe('10.000');
    $this->assertDatabaseCount('inventory_movements', 0);
    $record = $this->adjustments->save($this->actor, $record->id, array_replace($this->input, ['adjustment_type' => 'issue', 'quantity' => 2, 'unit_cost' => null, 'revision' => $this->adjustments->revision($record)]));
    $this->adjustments->act($this->actor, $record->id, 'post', $this->adjustments->revision($record));
    $this->getJson($this->path.'/'.$record->id)->assertJsonPath('data.movements.0.unit_cost', '5.0000');
});

it('deletes draft selections atomically and checks individual and bulk grants', function () {
    $draft = $this->adjustments->save($this->actor, null, $this->input);
    $posted = $this->adjustments->save($this->actor, null, $this->input);
    $this->adjustments->act($this->actor, $posted->id, 'post', $this->adjustments->revision($posted));
    $this->postJson($this->path.'/records/delete', ['ids' => [$draft->id, $posted->id]])->assertConflict();
    expect($draft->fresh())->not->toBeNull();
    $this->actor->revokePermissionTo('DeleteAny:InventoryAdjustment');
    $this->postJson($this->path.'/records/delete', ['ids' => [$draft->id]])->assertForbidden();
    $this->actor->givePermissionTo('DeleteAny:InventoryAdjustment');
    $this->postJson($this->path.'/records/delete', ['ids' => [$draft->id, $this->privateRecord->id]])->assertNotFound();
    $this->postJson($this->path.'/records/delete', ['ids' => [$draft->id]])->assertOk();
});

it('denies module-disabled APIs and Admin and revoked posting permissions', function () {
    $record = $this->adjustments->save($this->actor, null, $this->input);
    $this->actor->revokePermissionTo('Update:InventoryAdjustment');
    $this->postJson($this->path.'/'.$record->id.'/post', ['revision' => $this->adjustments->revision($record)])->assertForbidden();
    Organization::query()->create(['name' => 'No inventory', 'metadata' => ['company_setup' => ['organization_saved' => true]]]);
    $this->getJson($this->path)->assertForbidden();
    $this->getJson($this->path.'/options/items')->assertForbidden();
    $this->postJson($this->path, $this->input)->assertForbidden();
    $this->postJson($this->path.'/records/delete', ['ids' => [$record->id]])->assertForbidden();
    $this->actingAs($this->actor, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(ListInventoryAdjustments::class)->assertForbidden();
});

it('submits rejects corrects resubmits and posts through configured approval', function () {
    $manager = reportingUser('Adjustment approver');
    $role = JobRole::query()->create(['name' => 'Adjustment approver', 'code' => 'ADJAPP', 'level' => 2]);
    $position = Position::query()->create(['name' => 'Adjustment review', 'code' => 'ADJPOS', 'job_role_id' => $role->id]);
    $manager->employee->positions()->attach($position->id);
    ApprovalSetting::instance()->update(['enabled_modules' => [InventoryAdjustment::class]]);
    $flow = ApprovalFlow::query()->create(['module' => 'InventoryAdjustment', 'active' => true]);
    ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'step_order' => 1, 'job_role_id' => $role->id, 'territory_scope' => 'all']);
    $record = $this->adjustments->save($this->actor, null, $this->input);
    $path = $this->path.'/'.$record->id;
    $this->postJson($path.'/post', ['revision' => $this->adjustments->revision($record)])->assertConflict();
    $record->forceFill(['reason' => ' '])->saveQuietly();
    $this->postJson($path.'/submit', ['revision' => $this->adjustments->revision($record)])->assertUnprocessable();
    $record->forceFill(['reason' => 'Stock reconciliation'])->saveQuietly();
    $this->adjustments->act($this->actor, $record->id, 'submit', $this->adjustments->revision($record));
    $record->refresh();
    $this->postJson($path.'/delete', ['revision' => $this->adjustments->revision($record)])->assertConflict();
    app(ApprovalService::class)->reject($record->approval, $manager->id, 'Review cost');
    $record->refresh();
    $record = $this->adjustments->save($this->actor, $record->id, $this->input + ['revision' => $this->adjustments->revision($record)]);
    $this->adjustments->act($this->actor, $record->id, 'submit', $this->adjustments->revision($record));
    $record->refresh();
    app(ApprovalService::class)->approve($record->approval, $manager->id);
    $record->refresh();
    $this->postJson($path.'/post', ['revision' => $this->adjustments->revision($record)])->assertOk();
});

it('shares Admin saves posted review and atomic deletion guards with mobile', function () {
    $this->actingAs($this->actor, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(CreateInventoryAdjustment::class)->fillForm($this->input)->call('create')->assertHasNoFormErrors();
    $record = InventoryAdjustment::query()->where('created_by', $this->actor->id)->firstOrFail();
    Livewire::test(EditInventoryAdjustment::class, ['record' => $record->id])->fillForm(['reason' => 'Reviewed count'])->call('save')->assertHasNoFormErrors()->callAction('post')->assertNotified();
    Livewire::test(EditInventoryAdjustment::class, ['record' => $record->id])->assertSuccessful()->assertActionHidden('delete')->assertActionHidden('post');
    $draft = $this->adjustments->save($this->actor, null, $this->input);
    Livewire::test(ListInventoryAdjustments::class)->callTableBulkAction('delete', [$draft, $record])->assertNotified();
    expect($draft->fresh())->not->toBeNull();
});

it('rejects cancelled adjustments and missing reasons in the posting service', function () {
    $record = $this->adjustments->save($this->actor, null, $this->input);
    $record->forceFill(['status' => InventoryDocumentStatus::Cancelled])->saveQuietly();
    expect(fn () => app(InventoryService::class)->postAdjustment($record, $this->actor->id))->toThrow(RuntimeException::class, 'Only draft adjustments can be posted.');
    $record->forceFill(['status' => InventoryDocumentStatus::Draft, 'reason' => ' '])->saveQuietly();
    expect(fn () => app(InventoryService::class)->postAdjustment($record, $this->actor->id))->toThrow(RuntimeException::class, 'A reason is required for stock adjustments.');
    $this->assertDatabaseCount('inventory_movements', 0);
});
