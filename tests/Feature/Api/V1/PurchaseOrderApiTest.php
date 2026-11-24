<?php

use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Models\AccountMaster;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalSetting;
use App\Models\GoodsReceiptNote;
use App\Models\JobRole;
use App\Models\Organization;
use App\Models\Position;
use App\Models\PurchaseOrder;
use App\Models\TypeMaster;
use App\Services\Approval\ApprovalService;
use App\Services\Inventory\PurchaseOrderRegister;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actor = reportingUser('PO Buyer');
    $this->outside = reportingUser('Other Buyer');
    foreach (['ViewAny:PurchaseOrder', 'View:PurchaseOrder', 'Create:PurchaseOrder', 'Update:PurchaseOrder', 'Delete:PurchaseOrder', 'DeleteAny:PurchaseOrder', 'Create:GoodsReceiptNote', 'ViewAny:GoodsReceiptNote', 'View:GoodsReceiptNote'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $this->actor->givePermissionTo($permission);
    }
    Sanctum::actingAs($this->outside);
    $this->privateSupplier = reportingDoctor($this->outside, 'Private supplier');
    $this->privateLocation = reportingLocation('Private receiving');
    $this->privateOrder = PurchaseOrder::query()->create(['supplier_id' => $this->privateSupplier->id, 'location_master_id' => $this->privateLocation->id, 'order_date' => today()]);
    Sanctum::actingAs($this->actor);
    $vendor = TypeMaster::query()->firstOrCreate(['name' => 'Vendor', 'typeable_type' => AccountMaster::class]);
    $this->supplier = reportingDoctor($this->actor, 'Visible supplier');
    $this->supplier->update(['type_master_id' => $vendor->id]);
    $this->privateSupplier->update(['type_master_id' => $vendor->id]);
    $this->location = reportingLocation('Visible receiving');
    $this->item = reportingSampleItem('PO-ITEM', 'Purchased item');
    $this->path = '/api/v1/purchase-orders';
    $this->input = ['supplier_id' => $this->supplier->id, 'location_master_id' => $this->location->id,
        'order_date' => today()->toDateString(), 'currency' => 'INR', 'notes' => 'Test purchase',
        'lines' => [['item_master_id' => $this->item->id, 'quantity_ordered' => '3.125', 'unit_price' => '10.1234', 'tax_rate' => '10']]];
    $this->orders = app(PurchaseOrderRegister::class);
});

it('negotiates purchases and scopes registers filters choices and direct access', function () {
    $record = $this->orders->save($this->actor, null, $this->input);
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertOk()->assertJsonFragment(['key' => 'g07-03', 'screen_key' => 'purchase-orders']);
    $this->getJson($this->path)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $record->id);
    $this->getJson($this->path.'?search=Private')->assertJsonCount(0, 'data');
    $this->getJson($this->path.'?supplier_id='.$this->privateSupplier->id)->assertJsonCount(0, 'data');
    $this->getJson($this->path.'?status=approved')->assertJsonCount(0, 'data');
    $this->getJson($this->path.'?from='.today()->addDay()->toDateString())->assertJsonCount(0, 'data');
    $this->getJson($this->path.'/options/suppliers')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->supplier->id);
    $this->getJson($this->path.'/options/locations?search=Private')->assertJsonCount(0, 'data');
    $this->getJson($this->path.'/'.$this->privateOrder->id)->assertNotFound();
    $this->actor->revokePermissionTo('View:PurchaseOrder');
    $this->getJson($this->path)->assertForbidden();
    $this->getJson($this->path.'/options/items')->assertForbidden();
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertJsonMissing(['key' => 'g07-03']);
});

it('creates once calculates totals validates choices and updates only owned lines with a revision', function () {
    $key = (string) Str::uuid();
    $response = $this->withHeader('Idempotency-Key', $key)->postJson($this->path, $this->input)->assertCreated();
    $id = $response->json('data.id');
    $this->withHeader('Idempotency-Key', $key)->postJson($this->path, $this->input)->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $id);
    $this->flushHeaders();
    $detail = $this->getJson($this->path.'/'.$id)->assertOk()->assertJsonPath('data.subtotal', '31.64')->assertJsonPath('data.tax_amount', '3.16')->assertJsonPath('data.total_amount', '34.80')->json('data');
    $payload = $this->input + ['revision' => $detail['revision']];
    $payload['lines'][0]['id'] = $detail['lines'][0]['id'];
    $payload['lines'][0]['quantity_ordered'] = '4';
    $this->patchJson($this->path.'/'.$id, $payload)->assertOk()->assertJsonPath('data.lines.0.quantity_received', '0.000');
    $this->patchJson($this->path.'/'.$id, $payload)->assertConflict();
    $payload['revision'] = $this->getJson($this->path.'/'.$id)->json('data.revision');
    $payload['lines'][0]['id'] = 999999;
    $this->patchJson($this->path.'/'.$id, $payload)->assertUnprocessable();
    $bad = $this->input;
    $bad['supplier_id'] = $this->privateSupplier->id;
    $this->postJson($this->path, $bad)->assertUnprocessable();
    $bad = $this->input;
    $bad['lines'][0]['quantity_ordered'] = '1.0001';
    $this->postJson($this->path, $bad)->assertUnprocessable();
    $bad['lines'][0]['quantity_ordered'] = '1';
    $bad['lines'][0]['quantity_received'] = 999;
    $this->postJson($this->path, $bad)->assertUnprocessable();
    $this->assertDatabaseCount('purchase_orders', 2);
});

it('locks approved purchase orders against edits deletion and bulk deletion', function () {
    $record = $this->orders->save($this->actor, null, $this->input);
    $path = $this->path.'/'.$record->id;
    $this->postJson($path.'/approve', ['revision' => $this->orders->revision($record)])->assertOk();
    $record->refresh();
    expect($record->status)->toBe(PurchaseOrderStatus::Approved);
    $this->patchJson($path, $this->input + ['revision' => $this->orders->revision($record)])->assertConflict();
    $this->postJson($path.'/delete', ['revision' => $this->orders->revision($record)])->assertConflict();
    $draft = $this->orders->save($this->actor, null, $this->input);
    $this->postJson($this->path.'/records/delete', ['ids' => [$draft->id, $record->id]])->assertConflict();
    expect($draft->fresh())->not->toBeNull();
    $this->actor->revokePermissionTo('DeleteAny:PurchaseOrder');
    $this->postJson($this->path.'/records/delete', ['ids' => [$draft->id]])->assertForbidden();
    $this->postJson($this->path.'/'.$draft->id.'/delete', ['revision' => $this->orders->revision($draft)])->assertOk();
});

it('copies only remaining PO quantities into one scoped draft receipt and never posts stock', function () {
    $record = $this->orders->save($this->actor, null, $this->input);
    $line = $record->lines()->first();
    $line->forceFill(['quantity_received' => '1.125'])->saveQuietly();
    $record->forceFill(['status' => PurchaseOrderStatus::PartiallyReceived, 'approval_status' => 'approved'])->saveQuietly();
    $path = $this->path.'/'.$record->id;
    $revision = $this->orders->revision($record->refresh());
    $this->actor->revokePermissionTo('Create:GoodsReceiptNote');
    $this->postJson($path.'/create-grn', ['revision' => $revision])->assertForbidden();
    $this->actor->givePermissionTo('Create:GoodsReceiptNote');
    $key = (string) Str::uuid();
    $first = $this->withHeader('Idempotency-Key', $key)->postJson($path.'/create-grn', ['revision' => $revision])->assertOk()->json('data.goods_receipt_note_id');
    $this->withHeader('Idempotency-Key', $key)->postJson($path.'/create-grn', ['revision' => $revision])->assertOk()->assertJsonPath('data.goods_receipt_note_id', $first);
    $this->flushHeaders();
    $this->postJson($path.'/create-grn', ['revision' => $this->orders->revision($record->refresh())])->assertOk()->assertJsonPath('data.goods_receipt_note_id', $first);
    $receipt = GoodsReceiptNote::query()->findOrFail($first);
    expect($receipt->lines()->first()->quantity_received)->toBe('2.000');
    $this->assertDatabaseCount('goods_receipt_notes', 1);
    $this->assertDatabaseCount('inventory_movements', 0);
    $this->getJson($path)->assertJsonCount(1, 'data.receipts');
    $this->actor->revokePermissionTo('View:GoodsReceiptNote');
    $this->getJson($path)->assertJsonCount(0, 'data.receipts');
});

it('uses configured approval submission rejection and resubmission without allowing the approve shortcut', function () {
    $manager = reportingUser('PO Approver');
    $role = JobRole::query()->create(['name' => 'PO Approver', 'code' => 'POAPP', 'level' => 2]);
    $position = Position::query()->create(['name' => 'PO Approval', 'code' => 'POAPPPOS', 'job_role_id' => $role->id]);
    $manager->employee->positions()->attach($position->id);
    ApprovalSetting::instance()->update(['enabled_modules' => [PurchaseOrder::class]]);
    $flow = ApprovalFlow::query()->create(['module' => 'PurchaseOrder', 'active' => true]);
    ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'step_order' => 1, 'job_role_id' => $role->id, 'territory_scope' => 'all']);
    $record = $this->orders->save($this->actor, null, $this->input);
    $path = $this->path.'/'.$record->id;
    $this->postJson($path.'/approve', ['revision' => $this->orders->revision($record)])->assertConflict();
    $this->postJson($path.'/submit', ['revision' => $this->orders->revision($record)])->assertOk();
    $record->refresh();
    expect($record->approval_status)->toBe('pending');
    $this->postJson($path.'/delete', ['revision' => $this->orders->revision($record)])->assertConflict();
    app(ApprovalService::class)->reject($record->approval, $manager->id, 'Fix price');
    $record->refresh();
    $this->postJson($path.'/submit', ['revision' => $this->orders->revision($record)])->assertOk();
    $record->refresh();
    app(ApprovalService::class)->approve($record->approval, $manager->id);
    expect($record->fresh()->status)->toBe(PurchaseOrderStatus::Approved);
});

it('keeps purchases and receipt handoff outside the optional inventory module', function () {
    Organization::query()->create(['name' => 'No inventory', 'metadata' => ['company_setup' => ['organization_saved' => true]]]);
    $record = $this->orders->save($this->actor, null, $this->input);
    $this->getJson($this->path)->assertOk();
    $this->postJson($this->path.'/'.$record->id.'/approve', ['revision' => $this->orders->revision($record)])->assertOk();
    $this->postJson($this->path.'/'.$record->id.'/create-grn', ['revision' => $this->orders->revision($record->refresh())])->assertOk();
});

it('shares Admin creation editing and locked action availability with mobile', function () {
    $this->actingAs($this->actor, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(CreatePurchaseOrder::class)->fillForm($this->input)->call('create')->assertHasNoFormErrors();
    $record = PurchaseOrder::query()->where('created_by', $this->actor->id)->firstOrFail();
    Livewire::test(EditPurchaseOrder::class, ['record' => $record->id])->fillForm(['notes' => 'Admin revised'])->call('save')->assertHasNoFormErrors();
    expect($record->refresh()->notes)->toBe('Admin revised');
    $this->orders->act($this->actor, [$record->id], 'approve', $this->orders->revision($record));
    Livewire::test(EditPurchaseOrder::class, ['record' => $record->id])->assertActionHidden('delete')->assertActionHidden('approve')->assertActionVisible('create-grn');
});

it('cancels editable orders without allowing further changes or approval', function () {
    $record = $this->orders->save($this->actor, null, $this->input);
    $path = $this->path.'/'.$record->id;
    $this->actor->revokePermissionTo('Update:PurchaseOrder');
    $this->postJson($path.'/cancel', ['revision' => $this->orders->revision($record)])->assertForbidden();
    $this->patchJson($path, $this->input + ['revision' => $this->orders->revision($record)])->assertForbidden();
    $this->actor->givePermissionTo('Update:PurchaseOrder');
    $this->postJson($path.'/cancel', ['revision' => $this->orders->revision($record)])->assertOk();
    expect($record->fresh()->status)->toBe(PurchaseOrderStatus::Cancelled);
    $this->postJson($path.'/approve', ['revision' => $this->orders->revision($record->refresh())])->assertConflict();
    $this->getJson($path)->assertJsonPath('data.can_edit', false)->assertJsonCount(0, 'data.actions');
});

it('rejects all-or-nothing bulk submission and changed receipt prerequisites', function () {
    $draft = $this->orders->save($this->actor, null, $this->input);
    $this->postJson($this->path.'/records/submit', ['ids' => [$draft->id, $this->privateOrder->id]])->assertNotFound();
    expect($draft->fresh()->approval_status)->toBe('draft');
    $this->postJson($this->path.'/records/delete', ['ids' => [$draft->id, $draft->id]])->assertUnprocessable();
    $this->orders->act($this->actor, [$draft->id], 'approve', $this->orders->revision($draft));
    $this->location->update(['is_active' => false]);
    $this->postJson($this->path.'/'.$draft->id.'/create-grn', ['revision' => $this->orders->revision($draft->refresh())])->assertUnprocessable();
    $this->assertDatabaseCount('goods_receipt_notes', 0);
});

it('reports a locked Admin bulk selection without deleting any draft', function () {
    $this->actingAs($this->actor, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $draft = $this->orders->save($this->actor, null, $this->input);
    $locked = $this->orders->save($this->actor, null, $this->input);
    $this->orders->act($this->actor, [$locked->id], 'approve', $this->orders->revision($locked));
    Livewire::test(ListPurchaseOrders::class)
        ->callTableBulkAction('delete', [$draft, $locked])->assertNotified();
    expect($draft->fresh())->not->toBeNull()->and($locked->fresh())->not->toBeNull();
});
