<?php

use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalSetting;
use App\Models\CustomerPrice;
use App\Models\JobRole;
use App\Models\Organization;
use App\Models\Position;
use App\Models\SalesDocumentPreference;
use App\Models\SalesOrder;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function salesOrderApiOwner(): User
{
    return grantPagePermissions(reportingUser('Order Owner'), 'ViewAny:SalesOrder', 'View:SalesOrder', 'Create:SalesOrder',
        'Update:SalesOrder', 'Delete:SalesOrder', 'ViewAny:AccountMaster', 'View:AccountMaster', 'ViewOwn:AccountMaster');
}

function salesOrderApiPayload(User $owner): array
{
    $customer = reportingDoctor($owner, 'Order Buyer');
    $contact = reportingContact('Order', 'Buyer');
    $customer->contactDetails()->attach($contact);
    $item = reportingSampleItem('ORD', 'Order Product');
    $item->update(['selling_price' => 100]);
    $tax = Tax::query()->create(['name' => 'IGST 18', 'total_rate' => 18, 'is_active' => true]);
    $tax->components()->create(['type' => 'IGST', 'rate' => 18]);
    $item->taxes()->attach($tax);
    CustomerPrice::query()->create(['customer_id' => $customer->id, 'item_master_id' => $item->id, 'price' => 80, 'discount' => 10]);

    return [
        'account_master_id' => $customer->id,
        'contact_detail_id' => $contact->id,
        'date' => today()->toDateString(),
        'delivery_date' => today()->addDays(2)->toDateString(),
        'other_ref' => 'CUSTOMER-PO',
        'items' => [['item_master_id' => $item->id, 'quantity' => 1.25]],
    ];
}

it('prices fractional orders and preserves scoped navigation, PDF and create retries', function () {
    $owner = salesOrderApiOwner();
    $payload = salesOrderApiPayload($owner);
    $organization = Organization::query()->create(['name' => 'Order Organization']);
    SalesDocumentPreference::query()->create(['discount_level' => 'both']);
    Sanctum::actingAs($owner);

    $url = '/api/v1/sales-orders';
    $headers = ['Idempotency-Key' => (string) Str::uuid()];
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertOk()
        ->assertJsonFragment(['key' => 'g04-06', 'screen_key' => 'sales-orders']);
    $this->getJson('/api/v1/quotes')->assertForbidden();
    $this->postJson($url, [...$payload, 'contact_detail_id' => reportingContact('Unlinked', 'Contact')->id])
        ->assertUnprocessable()->assertJsonValidationErrors('contact_detail_id');

    $response = $this->postJson($url, $payload + ['discount_type' => 'percentage', 'discount_value' => 10, 'total' => 1], $headers)
        ->assertCreated()->assertJsonPath('data.items.0.quantity', '1.250')
        ->assertJsonPath('data.tax_details.0.type', 'IGST')
        ->assertJsonPath('data.other_ref', 'CUSTOMER-PO');
    $order = SalesOrder::query()->findOrFail($response->json('data.id'));
    expect((float) $order->gross_total)->toBe(100.0)
        ->and((float) $order->subtotal)->toBe(81.0)
        ->and((float) $order->total)->toBe(95.58);
    $this->postJson($url, $payload + ['discount_type' => 'percentage', 'discount_value' => 10, 'total' => 1], $headers)
        ->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $order->id);
    $this->assertDatabaseCount('inventory_movements', 0);

    $path = $url.'/'.$order->id;
    $this->patchJson($path, ['other_ref' => 'CUSTOMER-PO-EDIT'])->assertOk();
    $this->getJson($url.'?search=PO-EDIT&status=draft&per_page=1')->assertOk()->assertJsonPath('total', 1);
    $this->get($path.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($this->get($path.'/pdf?download=1')->assertOk()->headers->get('Content-Disposition'))->toStartWith('attachment');

    $owner->revokePermissionTo('Create:SalesOrder');
    $this->postJson($url, $payload + ['discount_type' => 'percentage', 'discount_value' => 10, 'total' => 1], $headers)->assertForbidden();

    $other = grantPagePermissions(reportingUser('Other Order Reader'), 'ViewAny:SalesOrder', 'View:SalesOrder');
    Sanctum::actingAs($other);
    $this->getJson($url)->assertOk()->assertJsonPath('total', 0);
    $this->getJson($path)->assertNotFound();
    $this->getJson($path.'/pdf')->assertNotFound();

    Sanctum::actingAs($owner);
    $organization->update(['metadata' => ['company_setup' => ['organization_saved' => true], 'optional_modules' => ['crm' => ['enabled' => false]]]]);
    $this->getJson($url)->assertForbidden();
    $this->getJson($path.'/pdf')->assertForbidden();
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertOk()
        ->assertJsonMissing(['key' => 'g04-06', 'screen_key' => 'sales-orders']);
});

it('submits orders together and converts an approved order to one linked invoice', function () {
    $owner = salesOrderApiOwner();
    $approver = grantPagePermissions(reportingUser('Order Approver'), 'View:MyApprovals');
    $role = JobRole::create(['name' => 'Order Approver', 'code' => 'SOA', 'level' => 2]);
    $position = Position::create(['name' => 'Order Review', 'code' => 'SOAR', 'job_role_id' => $role->id]);
    $approver->employee->positions()->attach($position->id);
    ApprovalSetting::instance()->update(['enabled_modules' => [SalesOrder::class]]);
    $flow = ApprovalFlow::create(['module' => 'SalesOrder', 'active' => true]);
    ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'step_order' => 1, 'job_role_id' => $role->id, 'territory_scope' => 'all']);
    $payload = salesOrderApiPayload($owner);
    Sanctum::actingAs($owner);

    $url = '/api/v1/sales-orders';
    $first = $this->postJson($url, $payload)->assertCreated()->assertJsonPath('data.can_submit', true)->json('data.id');
    $second = $this->postJson($url, $payload)->assertCreated()->json('data.id');
    $convert = ['action' => 'sales_invoice', 'ids' => [$first]];
    $this->postJson($url.'/actions', $convert)->assertForbidden();
    grantPagePermissions($owner, 'Create:SalesInvoice', 'ViewAny:SalesInvoice', 'View:SalesInvoice', 'OverrideApproval:SalesOrder');
    $this->postJson($url.'/actions', $convert)->assertUnprocessable();
    $this->postJson($url.'/actions', ['action' => 'submit', 'ids' => [$first, $second]])
        ->assertOk()->assertJsonPath('data.submitted', 2);
    $this->patchJson($url.'/'.$first, ['description' => 'Pending edit'])->assertUnprocessable();
    $this->deleteJson($url.'/'.$first)->assertUnprocessable();

    $order = SalesOrder::query()->findOrFail($first);
    $step = $order->approval->steps()->sole();
    Sanctum::actingAs($approver);
    $this->getJson('/api/v1/my-approvals/'.$step->id)->assertOk()->assertJsonPath('data.module', 'SalesOrder');
    $this->postJson('/api/v1/my-approvals/'.$step->id.'/decision', ['decision' => 'approve'])->assertOk();

    Sanctum::actingAs($owner);
    $this->getJson($url.'/'.$first)->assertOk()
        ->assertJsonPath('data.approval_status', 'approved')->assertJsonPath('data.can_convert_invoice', true);
    $this->patchJson($url.'/'.$first, ['other_ref' => 'APPROVED EDIT'])->assertUnprocessable();
    $this->postJson($url.'/actions', $convert)->assertOk()->assertJsonPath('data.created', true);
    $invoice = $order->salesInvoices()->sole();
    $this->postJson($url.'/actions', $convert)->assertOk()->assertJsonPath('data.created', false)->assertJsonPath('data.document.id', $invoice->id);
    $this->getJson($url.'/'.$first)->assertOk()->assertJsonPath('data.linked_invoices.0.id', $invoice->id);
    $this->getJson('/api/v1/sales-invoices/'.$invoice->id)->assertOk()->assertJsonPath('data.linked_orders.0.id', $first);
    expect($invoice->items()->sole()->quantity)->toBe('1.250')
        ->and((float) $invoice->total)->toBe((float) $order->total)
        ->and((float) $invoice->taxDetails()->sole()->amount)->toBe((float) $order->tax);
    $this->deleteJson($url.'/'.$first)->assertUnprocessable();
    $this->assertDatabaseCount('inventory_movements', 0);
});

it('checks every order before deleting drafts and protects delivered quantities', function () {
    $owner = salesOrderApiOwner();
    $payload = salesOrderApiPayload($owner);
    Sanctum::actingAs($owner);

    $url = '/api/v1/sales-orders';
    $draft = $this->postJson($url, $payload)->assertCreated()->json('data.id');
    $delivered = $this->postJson($url, $payload)->assertCreated()->json('data.id');
    SalesOrder::query()->findOrFail($delivered)->items()->update(['quantity_delivered' => 0.25]);
    $this->getJson($url.'/'.$delivered)->assertOk()->assertJsonPath('data.can_update', false)
        ->assertJsonPath('data.can_delete', false)->assertJsonPath('data.items.0.quantity_delivered', '0.250');
    $this->postJson($url.'/actions', ['action' => 'delete', 'ids' => [$draft, $delivered]])->assertUnprocessable();
    $this->assertNotSoftDeleted('sales_orders', ['id' => $draft]);
    $this->deleteJson($url.'/'.$draft)->assertOk()->assertJsonPath('data.deleted', 1);
    $this->assertSoftDeleted('sales_orders', ['id' => $draft, 'deleted_by' => $owner->id]);
});
