<?php

use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalSetting;
use App\Models\CustomerPrice;
use App\Models\JobRole;
use App\Models\Organization;
use App\Models\Position;
use App\Models\Quote;
use App\Models\SalesDocumentPreference;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function quoteApiPayload(User $owner): array
{
    $customer = reportingDoctor($owner, 'Quote Buyer '.$owner->id);
    $contact = reportingContact('Quote', 'Buyer');
    $customer->contactDetails()->attach($contact);
    $item = reportingSampleItem('QUO', 'Quoted Product');
    $item->update(['selling_price' => 100]);
    $tax = Tax::query()->create(['name' => 'GST 18', 'total_rate' => 18, 'is_active' => true]);
    $tax->components()->create(['type' => 'IGST', 'rate' => 18]);
    $item->taxes()->attach($tax);
    CustomerPrice::query()->create(['customer_id' => $customer->id, 'item_master_id' => $item->id, 'price' => 80, 'discount' => 10]);

    return [
        'account_master_id' => $customer->id,
        'contact_detail_id' => $contact->id,
        'date' => today()->toDateString(),
        'items' => [['item_master_id' => $item->id, 'quantity' => 2]],
    ];
}

it('prices quotes on the server and preserves stored pricing on a header-only edit', function () {
    $owner = grantPagePermissions(reportingUser('Quote Writer'), 'ViewAny:Quote', 'View:Quote', 'Create:Quote', 'Update:Quote',
        'ViewAny:AccountMaster', 'View:AccountMaster', 'ViewOwn:AccountMaster');
    $payload = quoteApiPayload($owner);
    SalesDocumentPreference::query()->create(['discount_level' => 'both']);
    Sanctum::actingAs($owner);
    $this->getJson('/api/v1/quotes/options')->assertOk()->assertJsonPath('data.discount_mode', 'both');
    $this->getJson('/api/v1/quotes/items?search=Quoted')->assertOk()->assertJsonPath('data.0.id', $payload['items'][0]['item_master_id']);
    $this->postJson('/api/v1/quotes', [...$payload, 'contact_detail_id' => reportingContact('Unlinked', 'Contact')->id])
        ->assertUnprocessable()->assertJsonValidationErrors('contact_detail_id');
    $response = $this->postJson('/api/v1/quotes', $payload + ['discount_value' => 10, 'discount_type' => 'percentage',
        'shipping_cost' => 10, 'round_off' => null, 'total' => 1, 'terms' => "Pay on delivery.\nKeep <this> text."])
        ->assertCreated()->assertJsonPath('data.can_update', true)->assertJsonPath('data.terms', "Pay on delivery.\nKeep <this> text.");
    $quote = Quote::query()->findOrFail($response->json('data.id'));
    expect((float) $quote->gross_total)->toBe(160.0)
        ->and((float) $quote->subtotal)->toBe(129.6)
        ->and((float) $quote->tax)->toBe(23.33)
        ->and((float) $quote->total)->toBe(162.93)
        ->and((float) $quote->items()->sole()->unit_price)->toBe(80.0);
    $response->assertJsonPath('data.tax_details.0.type', 'IGST');

    CustomerPrice::query()->update(['price' => 200]);
    $this->patchJson('/api/v1/quotes/'.$quote->id, ['description' => 'Only header changed'])->assertOk();
    expect((float) $quote->fresh()->total)->toBe(162.93);
    $this->patchJson('/api/v1/quotes/'.$quote->id, ['items' => [
        ['id' => $quote->items()->sole()->id, 'item_master_id' => $payload['items'][0]['item_master_id'], 'quantity' => 0.5],
    ]])->assertOk()->assertJsonPath('data.items.0.quantity', '0.500');
    expect((float) $quote->items()->sole()->unit_price)->toBe(80.0);
    $this->getJson('/api/v1/quotes?sent=1')->assertOk()->assertJsonPath('total', 0);
    $this->patchJson('/api/v1/quotes/'.$quote->id, ['status' => 'sent'])->assertOk()->assertJsonPath('data.sent', true);
    $this->getJson('/api/v1/quotes?sent=1&min_total=1&contact_detail_id='.$payload['contact_detail_id'])
        ->assertOk()->assertJsonPath('total', 1);
    $this->getJson('/api/v1/quotes/filter-options/contact_detail_id?search=Buyer')->assertOk()
        ->assertJsonPath('data.0.id', $payload['contact_detail_id']);
});

it('submits a batch through the approval engine and lets the assigned approver decide', function () {
    $owner = grantPagePermissions(reportingUser('Quote Requester'), 'ViewAny:Quote', 'View:Quote', 'Create:Quote', 'Update:Quote',
        'Create:SalesInvoice', 'ViewAny:SalesInvoice', 'View:SalesInvoice',
        'ViewAny:AccountMaster', 'View:AccountMaster', 'ViewOwn:AccountMaster');
    $approver = grantPagePermissions(reportingUser('Quote Approver'), 'View:MyApprovals');
    $role = JobRole::create(['name' => 'Quote Approver', 'code' => 'QUA', 'level' => 2]);
    $position = Position::create(['name' => 'Quote Review', 'code' => 'QUAR', 'job_role_id' => $role->id]);
    $approver->employee->positions()->attach($position->id);
    ApprovalSetting::instance()->update(['enabled_modules' => [Quote::class]]);
    $flow = ApprovalFlow::create(['module' => 'Quote', 'active' => true]);
    ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'step_order' => 1, 'job_role_id' => $role->id, 'territory_scope' => 'all']);

    $payload = quoteApiPayload($owner);
    Sanctum::actingAs($owner);
    $first = $this->postJson('/api/v1/quotes', $payload)->assertCreated()->assertJsonPath('data.can_submit', true)->json('data.id');
    $second = $this->postJson('/api/v1/quotes', $payload)->assertCreated()->json('data.id');
    $this->getJson('/api/v1/quotes')->assertOk()->assertJsonPath('data.0.can_submit', true)
        ->assertJsonPath('data.1.can_submit', true);
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertOk()
        ->assertJsonFragment(['key' => 'g04-05', 'screen_key' => 'quotes']);
    $this->postJson('/api/v1/quotes/actions', ['action' => 'sales_invoice', 'ids' => [$first]])->assertUnprocessable();
    $this->postJson('/api/v1/quotes/actions', ['action' => 'submit', 'ids' => [$first, $second]])
        ->assertOk()->assertJsonPath('data.submitted', 2);
    $this->getJson('/api/v1/quotes/'.$first)->assertOk()->assertJsonPath('data.can_update', false)
        ->assertJsonPath('data.can_convert_invoice', false);
    $approval = Quote::query()->findOrFail($first)->approval;
    $step = $approval->steps()->sole();

    Sanctum::actingAs($approver);
    $this->getJson('/api/v1/my-approvals/'.$step->id)->assertOk()->assertJsonPath('data.module', 'Quote');
    $this->postJson('/api/v1/my-approvals/'.$step->id.'/decision', ['decision' => 'approve'])->assertOk();
    Sanctum::actingAs($owner);
    $this->getJson('/api/v1/quotes/'.$first)->assertOk()->assertJsonPath('data.approval_status', 'approved');
    $this->postJson('/api/v1/quotes/actions', ['action' => 'sales_invoice', 'ids' => [$first]])
        ->assertOk()->assertJsonPath('data.created', true);
});

it('scopes quote reads and PDF exports, protects approved records and converts only once per target', function () {
    $owner = grantPagePermissions(reportingUser('Quote Lifecycle Owner'), 'ViewAny:Quote', 'View:Quote', 'Create:Quote', 'Delete:Quote',
        'ViewAny:SalesOrder', 'View:SalesOrder', 'ViewAny:AccountMaster', 'View:AccountMaster', 'ViewOwn:AccountMaster');
    $payload = quoteApiPayload($owner);
    $other = grantPagePermissions(reportingUser('Quote Lifecycle Other'), 'ViewAny:Quote', 'View:Quote', 'Delete:Quote');
    $hidden = Quote::withoutEvents(fn (): Quote => Quote::query()->create([
        ...collect($payload)->except('items')->all(),
        'document_number' => 'HIDDEN-QUOTE', 'created_by' => $other->id,
    ]));
    Organization::query()->create(['name' => 'Quote Export']);
    Sanctum::actingAs($owner);
    $id = $this->postJson('/api/v1/quotes', $payload)->assertCreated()->json('data.id');
    $this->getJson('/api/v1/quotes?search=Quote%20Buyer&status=draft')->assertOk()->assertJsonPath('total', 1);
    $this->getJson('/api/v1/quotes/'.$hidden->id.'/pdf')->assertNotFound();
    $this->get('/api/v1/quotes/'.$id.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $download = $this->get('/api/v1/quotes/'.$id.'/pdf?download=1')->assertOk();
    expect($download->headers->get('Content-Disposition'))->toStartWith('attachment');

    $action = '/api/v1/quotes/actions';
    $convert = ['action' => 'sales_order', 'ids' => [$id]];
    $this->postJson($action, $convert)->assertForbidden();
    grantPagePermissions($owner, 'Create:SalesOrder');
    $created = $this->postJson($action, $convert)->assertOk()->assertJsonPath('data.created', true)->json('data.document.id');
    $this->postJson($action, $convert)->assertOk()->assertJsonPath('data.created', false)->assertJsonPath('data.document.id', $created);
    expect(Quote::findOrFail($id)->salesOrders()->count())->toBe(1);
    $this->getJson('/api/v1/sales-orders/'.$created)->assertOk()
        ->assertJsonPath('data.linked_quotes.0.id', $id);
    $this->postJson($action, ['action' => 'delete', 'ids' => [$id, $hidden->id]])->assertNotFound();
    $this->assertNotSoftDeleted('quotes', ['id' => $id]);

    $quote = Quote::findOrFail($id);
    $flow = ApprovalFlow::query()->create(['module' => 'Quote', 'active' => false]);
    $approval = $quote->approval()->create([
        'approval_flow_id' => $flow->id, 'requested_by' => $owner->id, 'module' => 'Quote',
        'record_type' => Quote::class, 'record_id' => $quote->id, 'approval_status' => 'pending',
    ]);
    $this->deleteJson('/api/v1/quotes/'.$id)->assertUnprocessable();
    $this->postJson($action, $convert)->assertUnprocessable();
    $approval->update(['approval_status' => 'approved']);
    $this->deleteJson('/api/v1/quotes/'.$id)->assertUnprocessable();
    grantPagePermissions($owner, 'OverrideApproval:Quote');
    $this->deleteJson('/api/v1/quotes/'.$id)->assertOk()->assertJsonPath('data.deleted', 1);
    $this->assertSoftDeleted('quotes', ['id' => $id, 'deleted_by' => $owner->id]);
    $this->assertDatabaseHas('sales_orders', ['id' => $created, 'deleted_at' => null]);
});
