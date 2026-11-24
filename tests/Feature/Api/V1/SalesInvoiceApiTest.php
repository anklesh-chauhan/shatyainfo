<?php

use App\Filament\Resources\DeliveryChallans\Pages\CreateDeliveryChallan;
use App\Http\Middleware\PromptCompanySetup;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalSetting;
use App\Models\InventoryStock;
use App\Models\JobRole;
use App\Models\Organization;
use App\Models\Position;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function salesInvoiceApiOwner(): User
{
    return grantPagePermissions(reportingUser('Invoice Owner'), 'ViewAny:SalesInvoice', 'View:SalesInvoice',
        'Create:SalesInvoice', 'Update:SalesInvoice', 'Delete:SalesInvoice', 'ViewAny:AccountMaster', 'View:AccountMaster', 'ViewOwn:AccountMaster');
}

function salesInvoiceApiPayload(User $owner): array
{
    $customer = reportingDoctor($owner, 'Invoice Buyer');
    $contact = reportingContact('Invoice', 'Buyer');
    $customer->contactDetails()->attach($contact);
    $item = reportingSampleItem('INV-LINE', 'Invoice Product');
    $item->update(['selling_price' => 80]);

    return [
        'account_master_id' => $customer->id,
        'contact_detail_id' => $contact->id,
        'date' => today()->toDateString(),
        'due_date' => today()->addDays(10)->toDateString(),
        'payment_status' => 'Unpaid',
        'other_ref' => 'CUSTOMER-INV',
        'items' => [['item_master_id' => $item->id, 'quantity' => 3.125]],
    ];
}

it('prices invoices and scopes register navigation, edits, PDFs and bulk deletion', function () {
    $owner = salesInvoiceApiOwner();
    Sanctum::actingAs($owner);
    $payload = salesInvoiceApiPayload($owner);
    $organization = Organization::query()->create(['name' => 'Invoice Company']);
    Sanctum::actingAs($owner);
    $url = '/api/v1/sales-invoices';
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertOk()
        ->assertJsonFragment(['key' => 'g04-07', 'screen_key' => 'sales-invoices']);

    $id = $this->postJson($url, $payload + ['total' => 1])->assertCreated()
        ->assertJsonPath('data.items.0.quantity', '3.125')->assertJsonPath('data.payment_status', 'Unpaid')
        ->assertJsonPath('data.due_date', $payload['due_date'])->json('data.id');
    $this->assertDatabaseHas('sales_invoices', ['id' => $id, 'total' => 250, 'created_by' => $owner->id]);
    $path = $url.'/'.$id;
    $this->patchJson($path, ['payment_status' => 'Paid', 'paid_at' => today()->toDateString()])->assertOk()
        ->assertJsonPath('data.payment_status', 'Paid');
    $this->getJson($url.'?search=CUSTOMER-INV&status=draft')->assertOk()->assertJsonPath('total', 1);
    $this->get($path.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($this->get($path.'/pdf?download=1')->assertOk()->headers->get('Content-Disposition'))->toStartWith('attachment');
    $this->postJson($url.'/actions', ['action' => 'sales_invoice', 'ids' => [$id]])->assertUnprocessable();

    $other = grantPagePermissions(reportingUser('Other Invoice Reader'), 'ViewAny:SalesInvoice', 'View:SalesInvoice',
        'Delete:SalesInvoice', 'Create:DeliveryChallan');
    Sanctum::actingAs($other);
    $this->getJson($url)->assertOk()->assertJsonPath('total', 0);
    $this->getJson($path.'/pdf')->assertNotFound();
    $this->getJson($path.'/delivery-locations')->assertNotFound();
    $this->postJson($path.'/delivery-challans')->assertNotFound();
    $this->postJson($url.'/actions', ['action' => 'delete', 'ids' => [$id]])->assertNotFound();

    Sanctum::actingAs($owner);
    $second = $this->postJson($url, $payload)->assertCreated()->json('data.id');
    $this->postJson($url.'/actions', ['action' => 'delete', 'ids' => [$id, $second]])->assertOk()->assertJsonPath('data.deleted', 2);
    $this->assertSoftDeleted('sales_invoices', ['id' => $id, 'deleted_by' => $owner->id]);
    $organization->update(['metadata' => ['company_setup' => ['organization_saved' => true]]]);
    $this->getJson($url)->assertForbidden();
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertOk()
        ->assertJsonMissing(['key' => 'g04-07', 'screen_key' => 'sales-invoices']);
});

it('requires invoice approval and creates retry-safe partial challans without posting stock', function () {
    $owner = salesInvoiceApiOwner();
    $approver = grantPagePermissions(reportingUser('Invoice Approver'), 'View:MyApprovals');
    $role = JobRole::create(['name' => 'Invoice Approver', 'code' => 'SIA', 'level' => 2]);
    $position = Position::create(['name' => 'Invoice Review', 'code' => 'SIAR', 'job_role_id' => $role->id]);
    $approver->employee->positions()->attach($position->id);
    ApprovalSetting::instance()->update(['enabled_modules' => [SalesInvoice::class]]);
    $flow = ApprovalFlow::create(['module' => 'SalesInvoice', 'active' => true]);
    ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'step_order' => 1, 'job_role_id' => $role->id, 'territory_scope' => 'all']);
    Sanctum::actingAs($owner);
    $location = reportingLocation('Invoice Dispatch');
    Sanctum::actingAs($owner);
    $payload = salesInvoiceApiPayload($owner);
    $url = '/api/v1/sales-invoices';

    $id = $this->postJson($url, $payload)->assertCreated()->assertJsonPath('data.can_submit', true)->json('data.id');
    $second = $this->postJson($url, $payload)->assertCreated()->json('data.id');
    $invoice = SalesInvoice::query()->findOrFail($id);
    $path = $url.'/'.$id;
    $line = $invoice->items()->sole();
    $input = [
        'location_master_id' => $location->id,
        'delivery_date' => today()->toDateString(),
        'lines' => [['sales_document_item_id' => $line->id, 'quantity_delivered' => 1.125]],
    ];
    $headers = ['Idempotency-Key' => (string) Str::uuid()];
    $this->postJson($path.'/delivery-challans', $input, $headers)->assertForbidden();
    grantPagePermissions($owner, 'Create:DeliveryChallan', 'ViewAny:DeliveryChallan', 'View:DeliveryChallan');
    $this->getJson($path.'/delivery-locations')->assertUnprocessable();
    $this->postJson($path.'/delivery-challans', $input, ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
    $this->postJson($url.'/actions', ['action' => 'submit', 'ids' => [$id, $second]])
        ->assertOk()->assertJsonPath('data.submitted', 2);
    $this->patchJson($path, ['description' => 'Pending edit'])->assertUnprocessable();
    $this->deleteJson($path)->assertUnprocessable();

    $step = $invoice->approval->steps()->sole();
    Sanctum::actingAs($approver);
    $this->getJson('/api/v1/my-approvals/'.$step->id)->assertOk()->assertJsonPath('data.module', 'SalesInvoice');
    $this->postJson('/api/v1/my-approvals/'.$step->id.'/decision', ['decision' => 'approve'])->assertOk();
    Sanctum::actingAs($owner);
    $this->getJson($path)->assertOk()->assertJsonPath('data.approval_status', 'approved')->assertJsonPath('data.can_create_delivery_challan', true);
    $this->getJson($path.'/delivery-locations?search=Dispatch')->assertOk()->assertJsonPath('data.0.id', $location->id);
    $this->postJson($path.'/delivery-challans', $input)->assertUnprocessable()->assertJsonValidationErrors('operation_id');
    $this->postJson($path.'/delivery-challans', $input, $headers)->assertCreated();
    $this->postJson($path.'/delivery-challans', $input, $headers)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');

    $challan = $invoice->deliveryChallans()->sole();
    expect($challan->lines()->sole()->quantity_delivered)->toBe('1.125');
    $this->getJson($path)->assertOk()->assertJsonPath('data.linked_challans.0.id', $challan->id)
        ->assertJsonPath('data.items.0.available_to_dispatch', 2)
        ->assertJsonPath('data.items.0.remaining_quantity', 3.125);
    $this->assertDatabaseCount('inventory_movements', 0);
    $this->postJson($path.'/delivery-challans', [...$input, 'lines' => [[
        'sales_document_item_id' => $line->id, 'quantity_delivered' => 2.125,
    ]]], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
    $anotherLine = SalesInvoice::query()->findOrFail($second)->items()->sole();
    $this->postJson($path.'/delivery-challans', [...$input, 'lines' => [[
        'sales_document_item_id' => $anotherLine->id, 'quantity_delivered' => 1,
    ]]], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();

    $owner->revokePermissionTo('Create:DeliveryChallan');
    $this->postJson($path.'/delivery-challans', $input, $headers)->assertForbidden();
});

it('posts only valid invoice lines and protects delivered invoices from register changes', function () {
    $owner = grantPagePermissions(salesInvoiceApiOwner(), 'Create:DeliveryChallan', 'ViewAny:DeliveryChallan', 'View:DeliveryChallan');
    Sanctum::actingAs($owner);
    $payload = salesInvoiceApiPayload($owner);
    $location = reportingLocation('Stock Dispatch');
    Sanctum::actingAs($owner);
    $url = '/api/v1/sales-invoices';
    $invoice = SalesInvoice::query()->findOrFail($this->postJson($url, $payload)->assertCreated()->json('data.id'));
    $other = SalesInvoice::query()->findOrFail($this->postJson($url, $payload)->assertCreated()->json('data.id'));
    $line = $invoice->items()->sole();
    InventoryStock::query()->create(['item_master_id' => $line->item_master_id, 'location_master_id' => $location->id, 'quantity_on_hand' => 10]);
    $input = ['location_master_id' => $location->id, 'delivery_date' => today()->toDateString(),
        'lines' => [['sales_document_item_id' => $line->id, 'quantity_delivered' => 3.125]]];
    $path = $url.'/'.$invoice->id;
    $this->postJson($path.'/delivery-challans', $input, ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    $challan = $invoice->deliveryChallans()->sole();
    $challan->lines()->update(['sales_document_item_id' => $other->items()->sole()->id]);
    expect(fn () => app(InventoryService::class)->postDeliveryChallan($challan, $owner->id))
        ->toThrow(RuntimeException::class, 'Linked sales invoice line was not found for this item.');
    $this->assertDatabaseCount('inventory_movements', 0);

    $challan->lines()->update(['sales_document_item_id' => $line->id]);
    app(InventoryService::class)->postDeliveryChallan($challan, $owner->id);
    app(InventoryService::class)->postDeliveryChallan($challan, $owner->id);
    $this->assertDatabaseCount('inventory_movements', 1);
    $this->getJson($path)->assertOk()->assertJsonPath('data.status', 'accepted')
        ->assertJsonPath('data.can_create_delivery_challan', false)
        ->assertJsonPath('data.items.0.quantity_delivered', '3.125')
        ->assertJsonPath('data.can_update', false)->assertJsonPath('data.can_delete', false);
    $this->postJson($url.'/actions', ['action' => 'delete', 'ids' => [$other->id, $invoice->id]])->assertUnprocessable();
    $this->assertNotSoftDeleted('sales_invoices', ['id' => $other->id]);
});

it('prefills only visible invoices when creating a challan directly in Admin', function () {
    $owner = grantPagePermissions(salesInvoiceApiOwner(), 'Create:DeliveryChallan', 'ViewAny:DeliveryChallan', 'View:DeliveryChallan');
    Sanctum::actingAs($owner);
    $payload = salesInvoiceApiPayload($owner);
    $location = reportingLocation('Admin Dispatch');
    Sanctum::actingAs($owner);
    $invoice = SalesInvoice::query()->findOrFail($this->postJson('/api/v1/sales-invoices', $payload)->assertCreated()->json('data.id'));
    $this->withoutMiddleware(PromptCompanySetup::class)->withoutVite()->withoutExceptionHandling();
    $url = route('filament.admin.resources.delivery-challans.create', ['sales_invoice_id' => $invoice->id]);
    $this->actingAs($owner, 'web')->get($url)->assertOk()->assertSee('Invoice Product');

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $form = Livewire::withQueryParams(['sales_invoice_id' => $invoice->id])->test(CreateDeliveryChallan::class)
        ->assertOk()
        ->assertFormSet(['sales_invoice_id' => $invoice->id, 'customer_id' => $invoice->account_master_id]);
    expect(collect($form->get('data.lines'))->sole())->toMatchArray([
        'sales_document_item_id' => $invoice->items()->sole()->id,
        'quantity_delivered' => 3.125,
    ]);
    $form->set('data.location_master_id', $location->id)->call('create')->assertHasNoFormErrors();
    $challan = $invoice->deliveryChallans()->sole();
    expect($challan->lines()->sole()->quantity_delivered)->toBe('3.125');

    $other = grantPagePermissions(reportingUser('Other Dispatcher'), 'ViewAny:SalesInvoice', 'View:SalesInvoice', 'Create:DeliveryChallan',
        'ViewAny:DeliveryChallan', 'View:DeliveryChallan');
    $this->withExceptionHandling()->actingAs($other, 'web')->get($url)->assertNotFound();
});
