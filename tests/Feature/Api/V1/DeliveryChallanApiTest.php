<?php

use App\Enums\DeliveryChallanStatus;
use App\Filament\Resources\DeliveryChallans\Pages\CreateDeliveryChallan;
use App\Filament\Resources\DeliveryChallans\Pages\EditDeliveryChallan;
use App\Filament\Resources\DeliveryChallans\Pages\ListDeliveryChallans;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalSetting;
use App\Models\DeliveryChallan;
use App\Models\InventoryStock;
use App\Models\JobRole;
use App\Models\Organization;
use App\Models\Position;
use App\Models\SalesInvoice;
use App\Services\Approval\ApprovalService;
use App\Services\Inventory\DeliveryChallanRegister;
use App\Services\Inventory\InventoryService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actor = reportingUser('Challan Buyer');
    $this->outside = reportingUser('Other Challan Buyer');
    foreach (['ViewAny:DeliveryChallan', 'View:DeliveryChallan', 'Create:DeliveryChallan', 'Update:DeliveryChallan', 'Delete:DeliveryChallan', 'DeleteAny:DeliveryChallan', 'ViewAny:SalesInvoice', 'View:SalesInvoice'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $this->actor->givePermissionTo($permission);
    }
    Sanctum::actingAs($this->outside);
    $this->privateCustomer = reportingDoctor($this->outside, 'Private customer');
    $this->privateLocation = reportingLocation('Private dispatch');
    $this->privateChallan = DeliveryChallan::query()->create(['customer_id' => $this->privateCustomer->id, 'location_master_id' => $this->privateLocation->id, 'delivery_date' => today()]);
    Sanctum::actingAs($this->actor);
    $this->customer = reportingDoctor($this->actor, 'Visible vendor');
    $this->location = reportingLocation('Visible dispatch');
    $this->item = reportingSampleItem('DC-ITEM', 'Delivered item');
    $this->path = '/api/v1/delivery-challans';
    $this->input = ['customer_id' => $this->customer->id, 'location_master_id' => $this->location->id,
        'delivery_date' => today()->toDateString(), 'notes' => 'Challan note',
        'lines' => [['item_master_id' => $this->item->id, 'quantity_delivered' => '3.125', 'unit_cost' => '10.1234', 'batch_number' => 'LOT-1', 'remarks' => 'Received intact']]];
    $this->challans = app(DeliveryChallanRegister::class);
    $this->invoice = SalesInvoice::query()->create(['account_master_id' => $this->customer->id, 'contact_detail_id' => reportingContact('Invoice', 'Buyer')->id, 'date' => today(), 'document_number' => 'INV-DC-001', 'sales_person_id' => $this->actor->id, 'status' => 'draft']);
    $this->invoiceLine = $this->invoice->items()->create(['item_master_id' => $this->item->id, 'quantity' => 5, 'unit_price' => 10]);
    InventoryStock::query()->create(['item_master_id' => $this->item->id, 'location_master_id' => $this->location->id, 'quantity_on_hand' => 10, 'quantity_available' => 10, 'average_cost' => 10]);
});

it('negotiates DCs and scopes direct reads filters and invoice choices', function () {
    $record = $this->challans->save($this->actor, null, $this->input);
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertJsonFragment(['key' => 'g07-05', 'screen_key' => 'delivery-challans']);
    $this->getJson($this->path)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $record->id);
    $this->getJson($this->path.'?search=Private')->assertJsonCount(0, 'data');
    $this->getJson($this->path.'?customer_id='.$this->privateCustomer->id)->assertJsonCount(0, 'data');
    $this->getJson($this->path.'?status=posted')->assertJsonCount(0, 'data');
    $this->getJson($this->path.'?from='.today()->addDay()->toDateString())->assertJsonCount(0, 'data');
    $this->getJson($this->path.'/options/customers')->assertJsonCount(1, 'data');
    $this->getJson($this->path.'/sales-invoices/'.$this->invoice->id)->assertJsonPath('data.lines.0.sales_document_item_id', $this->invoiceLine->id);
    $this->getJson($this->path.'/'.$this->privateChallan->id)->assertNotFound();
    $this->actor->revokePermissionTo('View:SalesInvoice');
    $this->getJson($this->path.'/options/sales-invoices')->assertJsonCount(0, 'data');
    $this->getJson($this->path.'/sales-invoices/'.$this->invoice->id)->assertNotFound();
    $this->actor->revokePermissionTo('View:DeliveryChallan');
    $this->getJson($this->path)->assertForbidden();
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertJsonMissing(['key' => 'g07-05']);
});

it('creates once preserves quantities costs and batch metadata and checks revision and line ownership', function () {
    $key = (string) Str::uuid();
    $id = $this->withHeader('Idempotency-Key', $key)->postJson($this->path, $this->input)->assertCreated()->json('data.id');
    $this->withHeader('Idempotency-Key', $key)->postJson($this->path, $this->input)->assertCreated()->assertJsonPath('data.id', $id)->assertHeader('Idempotency-Replayed', 'true');
    $this->flushHeaders();
    $detail = $this->getJson($this->path.'/'.$id)->assertJsonPath('data.lines.0.quantity_delivered', '3.125')->assertJsonPath('data.lines.0.unit_cost', '10.1234')->assertJsonPath('data.lines.0.batch_number', 'LOT-1')->json('data');
    $input = $this->input + ['revision' => $detail['revision']];
    $input['lines'][0]['id'] = $detail['lines'][0]['id'];
    $input['lines'][0]['quantity_delivered'] = '2';
    $this->patchJson($this->path.'/'.$id, $input)->assertOk();
    $this->patchJson($this->path.'/'.$id, $input)->assertConflict();
    $input['revision'] = $this->getJson($this->path.'/'.$id)->json('data.revision');
    $input['lines'][0]['id'] = 999999;
    $this->patchJson($this->path.'/'.$id, $input)->assertUnprocessable();
    $bad = $this->input;
    $bad['lines'][0]['quantity_delivered'] = '1.0001';
    $this->postJson($this->path, $bad)->assertUnprocessable();
    $this->assertDatabaseCount('delivery_challans', 2);
    $this->assertDatabaseCount('inventory_movements', 0);
});

it('posts standalone stock once retains posted documents and exposes its movement history', function () {
    $record = $this->challans->save($this->actor, null, $this->input);
    $path = $this->path.'/'.$record->id;
    $body = ['revision' => $this->challans->revision($record)];
    $key = (string) Str::uuid();
    $this->withHeader('Idempotency-Key', $key)->postJson($path.'/post', $body)->assertOk();
    $this->withHeader('Idempotency-Key', $key)->postJson($path.'/post', $body)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
    $this->flushHeaders();
    app(InventoryService::class)->postDeliveryChallan($record->fresh(), $this->actor->id);
    $stock = InventoryStock::query()->firstOrFail();
    expect($stock->quantity_on_hand)->toBe('6.875')->and($stock->average_cost)->toBe('10.0000');
    $this->getJson($path)->assertJsonPath('data.posted_by', $this->actor->id)->assertJsonPath('data.can_edit', false)->assertJsonCount(1, 'data.movements')->assertJsonCount(0, 'data.actions');
    $this->patchJson($path, $this->input + ['revision' => $this->challans->revision($record->refresh())])->assertConflict();
    $this->postJson($path.'/delete', ['revision' => $this->challans->revision($record)])->assertConflict();
    $draft = $this->challans->save($this->actor, null, $this->input);
    $this->postJson($this->path.'/records/delete', ['ids' => [$draft->id, $record->id]])->assertConflict();
    expect($draft->fresh())->not->toBeNull();
    $this->assertDatabaseCount('inventory_movements', 1);
});

it('allocates drafts and posts partial invoice deliveries without over-delivery', function () {
    $input = $this->input + ['sales_invoice_id' => $this->invoice->id];
    $input['lines'][0]['sales_document_item_id'] = $this->invoiceLine->id;
    $input['lines'][0]['quantity_delivered'] = '3';
    $first = $this->challans->save($this->actor, null, $input);
    $staleFirst = $this->challans->revision($first);
    $this->postJson($this->path, $input)->assertUnprocessable();
    $input['lines'][0]['quantity_delivered'] = '2';
    $second = $this->challans->save($this->actor, null, $input);
    $staleSecond = $this->challans->revision($second);
    $this->postJson($this->path.'/'.$first->id.'/post', ['revision' => $staleFirst])->assertConflict();
    $this->challans->act($this->actor, [$first->id], 'post', $this->challans->revision($first->refresh()));
    expect($this->invoiceLine->fresh()->quantity_delivered)->toBe('3.000');
    $this->postJson($this->path.'/'.$second->id.'/post', ['revision' => $staleSecond])->assertConflict();
    $this->challans->act($this->actor, [$second->id], 'post', $this->challans->revision($second->refresh()));
    expect($this->invoice->fresh()->status)->toBe('accepted')->and($this->invoiceLine->fresh()->quantity_delivered)->toBe('5.000');
    expect(InventoryStock::query()->firstOrFail()->quantity_on_hand)->toBe('5.000');
    $this->assertDatabaseCount('inventory_movements', 2);
});

it('rejects foreign invoice lines item mismatches header overrides and repeated quantities atomically', function () {
    $input = $this->input + ['sales_invoice_id' => $this->invoice->id];
    $input['lines'][0]['sales_document_item_id'] = 999999;
    $this->postJson($this->path, $input)->assertUnprocessable();
    $input['lines'][0]['sales_document_item_id'] = $this->invoiceLine->id;
    $input['lines'][] = $input['lines'][0];
    $this->postJson($this->path, $input)->assertUnprocessable();
    array_pop($input['lines']);
    $input['customer_id'] = $this->privateCustomer->id;
    $this->postJson($this->path, $input)->assertUnprocessable();
    $input['customer_id'] = $this->customer->id;
    $other = reportingSampleItem('OTHER-DC', 'Other received item');
    $input['lines'][0]['item_master_id'] = $other->id;
    $this->postJson($this->path, $input)->assertUnprocessable();
    unset($input['sales_invoice_id']);
    $this->postJson($this->path, $input)->assertUnprocessable();
    $this->assertDatabaseCount('delivery_challans', 1);
});

it('rechecks post and bulk permissions and current selections', function () {
    $record = $this->challans->save($this->actor, null, $this->input);
    $path = $this->path.'/'.$record->id;
    $this->actor->revokePermissionTo('Update:DeliveryChallan');
    $this->postJson($path.'/post', ['revision' => $this->challans->revision($record)])->assertForbidden();
    $this->actor->givePermissionTo('Update:DeliveryChallan');
    $this->location->update(['is_active' => false]);
    $this->postJson($path.'/post', ['revision' => $this->challans->revision($record)])->assertUnprocessable();
    $this->actor->revokePermissionTo('DeleteAny:DeliveryChallan');
    $this->postJson($this->path.'/records/delete', ['ids' => [$record->id]])->assertForbidden();
    $this->actor->revokePermissionTo('Create:DeliveryChallan');
    $this->postJson($this->path, $this->input)->assertForbidden();
    $this->assertDatabaseCount('inventory_movements', 0);
});

it('uses challan approval submission rejection and resubmission before posting', function () {
    $manager = reportingUser('DC Approver');
    $role = JobRole::query()->create(['name' => 'DC Approver', 'code' => 'DCAPP', 'level' => 2]);
    $position = Position::query()->create(['name' => 'DC Approval', 'code' => 'DCAPPPOS', 'job_role_id' => $role->id]);
    $manager->employee->positions()->attach($position->id);
    ApprovalSetting::instance()->update(['enabled_modules' => [DeliveryChallan::class]]);
    $flow = ApprovalFlow::query()->create(['module' => 'DeliveryChallan', 'active' => true]);
    ApprovalFlowStep::query()->create(['approval_flow_id' => $flow->id, 'step_order' => 1, 'job_role_id' => $role->id, 'territory_scope' => 'all']);
    $record = $this->challans->save($this->actor, null, $this->input);
    $path = $this->path.'/'.$record->id;
    $this->postJson($path.'/post', ['revision' => $this->challans->revision($record)])->assertConflict();
    $this->postJson($path.'/submit', ['revision' => $this->challans->revision($record)])->assertOk();
    $record->refresh();
    $this->patchJson($path, $this->input + ['revision' => $this->challans->revision($record)])->assertConflict();
    $this->postJson($path.'/delete', ['revision' => $this->challans->revision($record)])->assertConflict();
    app(ApprovalService::class)->reject($record->approval, $manager->id, 'Check cost');
    $record->refresh();
    $this->postJson($path.'/submit', ['revision' => $this->challans->revision($record)])->assertOk();
    $record->refresh();
    app(ApprovalService::class)->approve($record->approval, $manager->id);
    $record->refresh();
    $this->postJson($path.'/post', ['revision' => $this->challans->revision($record)])->assertOk();
});

it('shares Admin draft saves posting and posted bulk deletion locks', function () {
    $this->actingAs($this->actor, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(CreateDeliveryChallan::class)->fillForm($this->input)->call('create')->assertHasNoFormErrors();
    $record = DeliveryChallan::query()->where('created_by', $this->actor->id)->firstOrFail();
    Livewire::test(EditDeliveryChallan::class, ['record' => $record->id])->fillForm(['notes' => 'Checked challan'])->call('save')->assertHasNoFormErrors()->callAction('post')->assertNotified();
    expect($record->refresh()->isPosted())->toBeTrue();
    Livewire::test(EditDeliveryChallan::class, ['record' => $record->id])->assertSuccessful()->assertActionHidden('post')->assertActionHidden('delete');
    $draft = $this->challans->save($this->actor, null, $this->input);
    Livewire::test(ListDeliveryChallans::class)->callTableBulkAction('delete', [$draft, $record])->assertNotified();
    expect($draft->fresh())->not->toBeNull()->and($record->fresh())->not->toBeNull();
});

it('keeps standalone challan management outside the inventory toggle and retains archived labels after posting', function () {
    Organization::query()->create(['name' => 'No optional stock', 'metadata' => ['company_setup' => ['organization_saved' => true]]]);
    $record = $this->challans->save($this->actor, null, $this->input);
    $this->challans->act($this->actor, [$record->id], 'post', $this->challans->revision($record));
    $this->item->delete();
    $this->location->delete();
    $this->getJson($this->path.'/'.$record->id)->assertOk()->assertJsonPath('data.location', 'Visible dispatch')->assertJsonPath('data.lines.0.item', 'Delivered item');
});

it('rejects invalid invoice linkage and cancelled challans in the posting service itself', function () {
    $record = DeliveryChallan::query()->create(['sales_invoice_id' => $this->invoice->id, 'customer_id' => $this->customer->id, 'location_master_id' => $this->location->id, 'delivery_date' => today()]);
    $line = $record->lines()->create(['item_master_id' => $this->item->id, 'quantity_delivered' => 1, 'unit_cost' => 10]);
    expect(fn () => app(InventoryService::class)->postDeliveryChallan($record, $this->actor->id))->toThrow(RuntimeException::class, 'Each linked delivery line must belong to its sales invoice.');
    $line->update(['sales_document_item_id' => $this->invoiceLine->id]);
    $record->forceFill(['status' => DeliveryChallanStatus::Cancelled])->saveQuietly();
    expect(fn () => app(InventoryService::class)->postDeliveryChallan($record, $this->actor->id))->toThrow(RuntimeException::class, 'Only draft delivery challans can be posted.');
    $this->assertDatabaseCount('inventory_movements', 0);
    expect($this->invoiceLine->fresh()->quantity_delivered)->toBe('0.000');
});

it('keeps a fully delivered invoice-linked challan reviewable in Admin', function () {
    $input = $this->input + ['sales_invoice_id' => $this->invoice->id];
    $input['lines'][0]['sales_document_item_id'] = $this->invoiceLine->id;
    $input['lines'][0]['quantity_delivered'] = '5';
    $record = $this->challans->save($this->actor, null, $input);
    $this->challans->act($this->actor, [$record->id], 'post', $this->challans->revision($record));
    expect($this->invoice->fresh()->status)->toBe('accepted');
    $this->actingAs($this->actor, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(EditDeliveryChallan::class, ['record' => $record->id])->assertSuccessful()->assertActionHidden('post')->assertActionHidden('delete');
});

it('rejects stock reserved for another operation and posts only after available stock is restored', function () {
    $stock = InventoryStock::query()->firstOrFail();
    $stock->update(['quantity_reserved' => 8, 'quantity_available' => 2]);
    $record = $this->challans->save($this->actor, null, $this->input);
    $path = $this->path.'/'.$record->id;
    $this->postJson($path.'/post', ['revision' => $this->challans->revision($record)])->assertUnprocessable();
    $this->assertDatabaseCount('inventory_movements', 0);
    expect($stock->refresh()->quantity_on_hand)->toBe('10.000')->and($record->fresh()->isPosted())->toBeFalse();
    $stock->update(['quantity_reserved' => 0, 'quantity_available' => 10]);
    $this->postJson($path.'/post', ['revision' => $this->challans->revision($record->refresh())])->assertOk();
    expect($stock->refresh()->quantity_on_hand)->toBe('6.875');
});

it('keeps the invoice handoff module checks and hides dispatch locations outside scope', function () {
    $this->getJson($this->path.'/options/locations?search=Private')->assertJsonCount(0, 'data');
    $input = $this->input;
    $input['location_master_id'] = $this->privateLocation->id;
    $this->postJson($this->path, $input)->assertUnprocessable();
    Organization::query()->create(['name' => 'No stock module', 'metadata' => ['company_setup' => ['organization_saved' => true], 'optional_modules' => ['crm' => ['enabled' => true]]]]);
    $this->getJson($this->path)->assertOk();
    $this->getJson($this->path.'/options/sales-invoices')->assertJsonCount(0, 'data');
    $this->getJson($this->path.'/sales-invoices/'.$this->invoice->id)->assertForbidden();
});
