<?php

use App\Enums\GoodsReceiptNoteStatus;
use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\GoodsReceiptNotes\Pages\CreateGoodsReceiptNote;
use App\Filament\Resources\GoodsReceiptNotes\Pages\EditGoodsReceiptNote;
use App\Filament\Resources\GoodsReceiptNotes\Pages\ListGoodsReceiptNotes;
use App\Models\AccountMaster;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalSetting;
use App\Models\GoodsReceiptNote;
use App\Models\InventoryStock;
use App\Models\JobRole;
use App\Models\Organization;
use App\Models\Position;
use App\Models\PurchaseOrder;
use App\Models\TypeMaster;
use App\Services\Approval\ApprovalService;
use App\Services\Inventory\GoodsReceiptRegister;
use App\Services\Inventory\InventoryService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actor = reportingUser('Receipt Buyer');
    $this->outside = reportingUser('Other Receipt Buyer');
    foreach (['ViewAny:GoodsReceiptNote', 'View:GoodsReceiptNote', 'Create:GoodsReceiptNote', 'Update:GoodsReceiptNote', 'Delete:GoodsReceiptNote', 'DeleteAny:GoodsReceiptNote', 'ViewAny:PurchaseOrder', 'View:PurchaseOrder'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $this->actor->givePermissionTo($permission);
    }
    Sanctum::actingAs($this->outside);
    $this->privateSupplier = reportingDoctor($this->outside, 'Private supplier');
    $this->privateLocation = reportingLocation('Private receiving');
    $this->privateReceipt = GoodsReceiptNote::query()->create(['supplier_id' => $this->privateSupplier->id, 'location_master_id' => $this->privateLocation->id, 'receipt_date' => today()]);
    Sanctum::actingAs($this->actor);
    $vendor = TypeMaster::query()->firstOrCreate(['name' => 'Vendor', 'typeable_type' => AccountMaster::class]);
    $this->supplier = reportingDoctor($this->actor, 'Visible vendor');
    $this->supplier->update(['type_master_id' => $vendor->id]);
    $this->privateSupplier->update(['type_master_id' => $vendor->id]);
    $this->location = reportingLocation('Visible receiving');
    $this->item = reportingSampleItem('GRN-ITEM', 'Received item');
    $this->path = '/api/v1/goods-receipt-notes';
    $this->input = ['supplier_id' => $this->supplier->id, 'location_master_id' => $this->location->id,
        'receipt_date' => today()->toDateString(), 'notes' => 'Receipt note',
        'lines' => [['item_master_id' => $this->item->id, 'quantity_received' => '3.125', 'unit_cost' => '10.1234', 'batch_number' => 'LOT-1', 'remarks' => 'Received intact']]];
    $this->receipts = app(GoodsReceiptRegister::class);
    $this->po = PurchaseOrder::query()->create(['supplier_id' => $this->supplier->id, 'location_master_id' => $this->location->id, 'order_date' => today()]);
    $this->poLine = $this->po->lines()->create(['item_master_id' => $this->item->id, 'quantity_ordered' => 5, 'unit_price' => 10]);
    $this->po->forceFill(['status' => PurchaseOrderStatus::Approved, 'approval_status' => 'approved'])->saveQuietly();
});

it('negotiates GRNs and scopes direct reads filters and purchase order choices', function () {
    $record = $this->receipts->save($this->actor, null, $this->input);
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertJsonFragment(['key' => 'g07-04', 'screen_key' => 'goods-receipt-notes']);
    $this->getJson($this->path)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $record->id);
    $this->getJson($this->path.'?search=Private')->assertJsonCount(0, 'data');
    $this->getJson($this->path.'?supplier_id='.$this->privateSupplier->id)->assertJsonCount(0, 'data');
    $this->getJson($this->path.'?status=posted')->assertJsonCount(0, 'data');
    $this->getJson($this->path.'?from='.today()->addDay()->toDateString())->assertJsonCount(0, 'data');
    $this->getJson($this->path.'/options/suppliers')->assertJsonCount(1, 'data');
    $this->getJson($this->path.'/purchase-orders/'.$this->po->id)->assertJsonPath('data.lines.0.purchase_order_line_id', $this->poLine->id);
    $this->getJson($this->path.'/'.$this->privateReceipt->id)->assertNotFound();
    $this->actor->revokePermissionTo('View:PurchaseOrder');
    $this->getJson($this->path.'/options/purchase-orders')->assertJsonCount(0, 'data');
    $this->getJson($this->path.'/purchase-orders/'.$this->po->id)->assertNotFound();
    $this->actor->revokePermissionTo('View:GoodsReceiptNote');
    $this->getJson($this->path)->assertForbidden();
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertJsonMissing(['key' => 'g07-04']);
});

it('creates once preserves quantities costs and batch metadata and checks revision and line ownership', function () {
    $key = (string) Str::uuid();
    $id = $this->withHeader('Idempotency-Key', $key)->postJson($this->path, $this->input)->assertCreated()->json('data.id');
    $this->withHeader('Idempotency-Key', $key)->postJson($this->path, $this->input)->assertCreated()->assertJsonPath('data.id', $id)->assertHeader('Idempotency-Replayed', 'true');
    $this->flushHeaders();
    $detail = $this->getJson($this->path.'/'.$id)->assertJsonPath('data.lines.0.quantity_received', '3.125')->assertJsonPath('data.lines.0.unit_cost', '10.1234')->assertJsonPath('data.lines.0.batch_number', 'LOT-1')->json('data');
    $input = $this->input + ['revision' => $detail['revision']];
    $input['lines'][0]['id'] = $detail['lines'][0]['id'];
    $input['lines'][0]['quantity_received'] = '2';
    $this->patchJson($this->path.'/'.$id, $input)->assertOk();
    $this->patchJson($this->path.'/'.$id, $input)->assertConflict();
    $input['revision'] = $this->getJson($this->path.'/'.$id)->json('data.revision');
    $input['lines'][0]['id'] = 999999;
    $this->patchJson($this->path.'/'.$id, $input)->assertUnprocessable();
    $bad = $this->input;
    $bad['lines'][0]['quantity_received'] = '1.0001';
    $this->postJson($this->path, $bad)->assertUnprocessable();
    $this->assertDatabaseCount('goods_receipt_notes', 2);
    $this->assertDatabaseCount('inventory_movements', 0);
});

it('posts standalone stock once retains posted documents and exposes its movement history', function () {
    $record = $this->receipts->save($this->actor, null, $this->input);
    $path = $this->path.'/'.$record->id;
    $body = ['revision' => $this->receipts->revision($record)];
    $key = (string) Str::uuid();
    $this->withHeader('Idempotency-Key', $key)->postJson($path.'/post', $body)->assertOk();
    $this->withHeader('Idempotency-Key', $key)->postJson($path.'/post', $body)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
    $this->flushHeaders();
    app(InventoryService::class)->postGrn($record->fresh(), $this->actor->id);
    $stock = InventoryStock::query()->firstOrFail();
    expect($stock->quantity_on_hand)->toBe('3.125')->and($stock->average_cost)->toBe('10.1234');
    $this->getJson($path)->assertJsonPath('data.posted_by', $this->actor->id)->assertJsonPath('data.can_edit', false)->assertJsonCount(1, 'data.movements')->assertJsonCount(0, 'data.actions');
    $this->patchJson($path, $this->input + ['revision' => $this->receipts->revision($record->refresh())])->assertConflict();
    $this->postJson($path.'/delete', ['revision' => $this->receipts->revision($record)])->assertConflict();
    $draft = $this->receipts->save($this->actor, null, $this->input);
    $this->postJson($this->path.'/records/delete', ['ids' => [$draft->id, $record->id]])->assertConflict();
    expect($draft->fresh())->not->toBeNull();
    $this->assertDatabaseCount('inventory_movements', 1);
});

it('posts partial PO receipts and rejects a stale competing receipt before over-receipt', function () {
    $input = $this->input + ['purchase_order_id' => $this->po->id];
    $input['lines'][0]['purchase_order_line_id'] = $this->poLine->id;
    $input['lines'][0]['quantity_received'] = '3';
    $first = $this->receipts->save($this->actor, null, $input);
    $second = $this->receipts->save($this->actor, null, $input);
    $staleRevision = $this->receipts->revision($second);
    $this->receipts->act($this->actor, [$first->id], 'post', $this->receipts->revision($first));
    expect($this->po->fresh()->status)->toBe(PurchaseOrderStatus::PartiallyReceived)->and($this->poLine->fresh()->quantity_received)->toBe('3.000');
    $this->postJson($this->path.'/'.$second->id.'/post', ['revision' => $staleRevision])->assertConflict();
    $this->postJson($this->path.'/'.$second->id.'/post', ['revision' => $this->receipts->revision($second->refresh())])->assertUnprocessable();
    expect(fn () => app(InventoryService::class)->postGrn($second->fresh(), $this->actor->id))->toThrow(RuntimeException::class, 'Received quantity exceeds remaining PO quantity.');
    $input['lines'][0]['quantity_received'] = '2';
    $input['revision'] = $this->receipts->revision($second->refresh());
    $second = $this->receipts->save($this->actor, $second->id, $input);
    $this->receipts->act($this->actor, [$second->id], 'post', $this->receipts->revision($second));
    expect($this->po->fresh()->status)->toBe(PurchaseOrderStatus::Received)->and($this->poLine->fresh()->quantity_received)->toBe('5.000');
    expect(InventoryStock::query()->firstOrFail()->quantity_on_hand)->toBe('5.000');
    $this->assertDatabaseCount('inventory_movements', 2);
});

it('rejects foreign PO lines item mismatches header overrides and repeated quantities atomically', function () {
    $input = $this->input + ['purchase_order_id' => $this->po->id];
    $input['lines'][0]['purchase_order_line_id'] = 999999;
    $this->postJson($this->path, $input)->assertUnprocessable();
    $input['lines'][0]['purchase_order_line_id'] = $this->poLine->id;
    $input['lines'][] = $input['lines'][0];
    $this->postJson($this->path, $input)->assertUnprocessable();
    array_pop($input['lines']);
    $input['supplier_id'] = $this->privateSupplier->id;
    $this->postJson($this->path, $input)->assertUnprocessable();
    $input['supplier_id'] = $this->supplier->id;
    $other = reportingSampleItem('OTHER-GRN', 'Other received item');
    $input['lines'][0]['item_master_id'] = $other->id;
    $this->postJson($this->path, $input)->assertUnprocessable();
    unset($input['purchase_order_id']);
    $this->postJson($this->path, $input)->assertUnprocessable();
    $this->assertDatabaseCount('goods_receipt_notes', 1);
});

it('rechecks post and bulk permissions and current selections', function () {
    $record = $this->receipts->save($this->actor, null, $this->input);
    $path = $this->path.'/'.$record->id;
    $this->actor->revokePermissionTo('Update:GoodsReceiptNote');
    $this->postJson($path.'/post', ['revision' => $this->receipts->revision($record)])->assertForbidden();
    $this->actor->givePermissionTo('Update:GoodsReceiptNote');
    $this->location->update(['is_active' => false]);
    $this->postJson($path.'/post', ['revision' => $this->receipts->revision($record)])->assertUnprocessable();
    $this->actor->revokePermissionTo('DeleteAny:GoodsReceiptNote');
    $this->postJson($this->path.'/records/delete', ['ids' => [$record->id]])->assertForbidden();
    $this->actor->revokePermissionTo('Create:GoodsReceiptNote');
    $this->postJson($this->path, $this->input)->assertForbidden();
    $this->assertDatabaseCount('inventory_movements', 0);
});

it('uses receipt approval submission rejection and resubmission before posting', function () {
    $manager = reportingUser('GRN Approver');
    $role = JobRole::query()->create(['name' => 'GRN Approver', 'code' => 'GRNAPP', 'level' => 2]);
    $position = Position::query()->create(['name' => 'GRN Approval', 'code' => 'GRNAPPPOS', 'job_role_id' => $role->id]);
    $manager->employee->positions()->attach($position->id);
    ApprovalSetting::instance()->update(['enabled_modules' => [GoodsReceiptNote::class]]);
    $flow = ApprovalFlow::query()->create(['module' => 'GoodsReceiptNote', 'active' => true]);
    ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'step_order' => 1, 'job_role_id' => $role->id, 'territory_scope' => 'all']);
    $record = $this->receipts->save($this->actor, null, $this->input);
    $path = $this->path.'/'.$record->id;
    $this->postJson($path.'/post', ['revision' => $this->receipts->revision($record)])->assertConflict();
    $this->postJson($path.'/submit', ['revision' => $this->receipts->revision($record)])->assertOk();
    $record->refresh();
    $this->patchJson($path, $this->input + ['revision' => $this->receipts->revision($record)])->assertConflict();
    $this->postJson($path.'/delete', ['revision' => $this->receipts->revision($record)])->assertConflict();
    app(ApprovalService::class)->reject($record->approval, $manager->id, 'Check cost');
    $record->refresh();
    $this->postJson($path.'/submit', ['revision' => $this->receipts->revision($record)])->assertOk();
    $record->refresh();
    app(ApprovalService::class)->approve($record->approval, $manager->id);
    $record->refresh();
    $this->postJson($path.'/post', ['revision' => $this->receipts->revision($record)])->assertOk();
});

it('shares Admin draft saves posting and posted bulk deletion locks', function () {
    $this->actingAs($this->actor, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(CreateGoodsReceiptNote::class)->fillForm($this->input)->call('create')->assertHasNoFormErrors();
    $record = GoodsReceiptNote::query()->where('created_by', $this->actor->id)->firstOrFail();
    Livewire::test(EditGoodsReceiptNote::class, ['record' => $record->id])->fillForm(['notes' => 'Checked receipt'])->call('save')->assertHasNoFormErrors()->callAction('post')->assertNotified();
    expect($record->refresh()->isPosted())->toBeTrue();
    Livewire::test(EditGoodsReceiptNote::class, ['record' => $record->id])->assertSuccessful()->assertActionHidden('post')->assertActionHidden('delete');
    $draft = $this->receipts->save($this->actor, null, $this->input);
    Livewire::test(ListGoodsReceiptNotes::class)->callTableBulkAction('delete', [$draft, $record])->assertNotified();
    expect($draft->fresh())->not->toBeNull()->and($record->fresh())->not->toBeNull();
});

it('keeps GRN management outside the inventory toggle and retains archived labels after posting', function () {
    Organization::query()->create(['name' => 'No optional stock', 'metadata' => ['company_setup' => ['organization_saved' => true]]]);
    $record = $this->receipts->save($this->actor, null, $this->input);
    $this->receipts->act($this->actor, [$record->id], 'post', $this->receipts->revision($record));
    $this->item->delete();
    $this->location->delete();
    $this->getJson($this->path.'/'.$record->id)->assertOk()->assertJsonPath('data.location', 'Visible receiving')->assertJsonPath('data.lines.0.item', 'Received item');
});

it('rejects invalid PO linkage and cancelled receipts in the posting service itself', function () {
    $record = GoodsReceiptNote::query()->create(['purchase_order_id' => $this->po->id, 'supplier_id' => $this->supplier->id, 'location_master_id' => $this->location->id, 'receipt_date' => today()]);
    $line = $record->lines()->create(['item_master_id' => $this->item->id, 'quantity_received' => 1, 'unit_cost' => 10]);
    expect(fn () => app(InventoryService::class)->postGrn($record, $this->actor->id))->toThrow(RuntimeException::class, 'Receipt items must match lines from the selected purchase order.');
    $line->update(['purchase_order_line_id' => $this->poLine->id]);
    $record->forceFill(['status' => GoodsReceiptNoteStatus::Cancelled])->saveQuietly();
    expect(fn () => app(InventoryService::class)->postGrn($record, $this->actor->id))->toThrow(RuntimeException::class, 'Only draft GRNs can be posted.');
    $this->assertDatabaseCount('inventory_movements', 0);
    expect($this->poLine->fresh()->quantity_received)->toBe('0.000');
});

it('keeps a fully received PO-linked GRN reviewable in Admin', function () {
    $input = $this->input + ['purchase_order_id' => $this->po->id];
    $input['lines'][0]['purchase_order_line_id'] = $this->poLine->id;
    $input['lines'][0]['quantity_received'] = '5';
    $record = $this->receipts->save($this->actor, null, $input);
    $this->receipts->act($this->actor, [$record->id], 'post', $this->receipts->revision($record));
    expect($this->po->fresh()->status)->toBe(PurchaseOrderStatus::Received);
    $this->actingAs($this->actor, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(EditGoodsReceiptNote::class, ['record' => $record->id])->assertSuccessful()->assertActionHidden('post')->assertActionHidden('delete');
});
