<?php

use App\Filament\Resources\CustomerPrices\Pages\CreateCustomerPrice;
use App\Filament\Resources\CustomerPrices\Pages\EditCustomerPrice;
use App\Filament\Resources\CustomerPrices\Pages\ListCustomerPrices;
use App\Models\ApprovalFlow;
use App\Models\CustomerPrice;
use App\Models\Organization;
use App\Models\Quote;
use App\Models\SalesDocumentPreference;
use App\Models\Tax;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

function pricingRegisterOwner(): User
{
    return grantPagePermissions(reportingUser('Price Manager'), 'ViewAny:CustomerPrice', 'View:CustomerPrice',
        'Create:CustomerPrice', 'Update:CustomerPrice', 'Delete:CustomerPrice', 'ViewAny:AccountMaster',
        'View:AccountMaster', 'ViewOwn:AccountMaster');
}

it('negotiates the scoped register and searches customer and variant selectors', function () {
    $owner = pricingRegisterOwner();
    $customer = reportingDoctor($owner, 'Managed Clinic');
    $hidden = reportingDoctor(reportingUser('Outside Price Owner'), 'Hidden Clinic');
    $parent = reportingSampleItem('CAT-P', 'Parent Therapy Product');
    $variant = reportingSampleItem('CAT-V', 'Standard Pack');
    $variant->update(['parent_id' => $parent->id, 'variant_name' => 'Pack 20', 'sku' => 'PACK-20']);
    $retired = reportingSampleItem('CAT-X', 'Retired Product');
    $retired->delete();
    $adminPrice = CustomerPrice::withoutEvents(fn (): CustomerPrice => CustomerPrice::create([
        'customer_id' => $customer->id, 'item_master_id' => $variant->id, 'price' => 70, 'discount' => 5,
    ]));
    CustomerPrice::withoutEvents(fn () => CustomerPrice::forceCreate([
        'customer_id' => $hidden->id, 'item_master_id' => $variant->id, 'price' => 12, 'discount' => 0, 'created_by' => $owner->id,
    ]));

    Sanctum::actingAs($owner);
    $path = '/api/v1/customer-prices';
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertOk()
        ->assertJsonFragment(['key' => 'g04-08', 'screen_key' => 'customer-prices']);
    $this->getJson($path)->assertOk()->assertJsonPath('total', 0)->assertJsonPath('can_create', true);
    grantPagePermissions($owner, 'ViewOwn:CustomerPrice');
    $this->getJson($path.'?search=Parent%20Therapy&per_page=1')->assertOk()->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.id', $adminPrice->id)->assertJsonPath('data.0.item_name', 'Parent Therapy Product – Pack 20');
    $this->getJson($path.'?customer_id='.$hidden->id)->assertOk()->assertJsonPath('total', 0);
    $this->getJson($path.'/options/customers')->assertOk()->assertJsonPath('data.0.id', $customer->id)->assertJsonCount(1, 'data');
    $this->getJson($path.'/options/items?search=PACK-20')->assertOk()->assertJsonPath('data.0.id', $variant->id);
    $this->getJson($path.'/options/items?search=Retired')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson($path, ['customer_id' => $hidden->id, 'item_master_id' => $parent->id, 'price' => 70])
        ->assertUnprocessable()->assertJsonValidationErrors('customer_id');
    $this->postJson($path, ['customer_id' => $customer->id, 'item_master_id' => $retired->id, 'price' => 70])
        ->assertUnprocessable()->assertJsonValidationErrors('item_master_id');

    $reader = grantPagePermissions(reportingUser('Price Viewer'), 'ViewAny:CustomerPrice');
    Sanctum::actingAs($reader);
    $this->getJson($path)->assertForbidden();
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertOk()
        ->assertJsonMissing(['key' => 'g04-08', 'screen_key' => 'customer-prices']);

    Sanctum::actingAs($owner);
    Organization::query()->create(['name' => 'CRM off', 'metadata' => ['company_setup' => ['organization_saved' => true]]]);
    $this->getJson($path)->assertForbidden();
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertOk()
        ->assertJsonMissing(['key' => 'g04-08', 'screen_key' => 'customer-prices']);
});

it('enforces one active pair, preserves deleted history, and validates bulk changes atomically', function () {
    $owner = pricingRegisterOwner();
    $customer = reportingDoctor($owner, 'Pricing Clinic');
    $item = reportingSampleItem('LIVE-A', 'Active One');
    $otherItem = reportingSampleItem('LIVE-B', 'Active Two');
    Sanctum::actingAs($owner);
    $path = '/api/v1/customer-prices';
    $input = ['customer_id' => $customer->id, 'item_master_id' => $item->id, 'price' => '75.25', 'discount' => '10.50'];

    $this->postJson($path, [...$input, 'discount' => 100.01])->assertUnprocessable()->assertJsonValidationErrors('discount');
    $this->postJson($path, [...$input, 'price' => -1])->assertUnprocessable()->assertJsonValidationErrors('price');
    $this->postJson($path, [...$input, 'price' => 1.001])->assertUnprocessable()->assertJsonValidationErrors('price');
    $first = $this->postJson($path, $input)->assertCreated()->assertJsonPath('data.price', '75.25')->json('data.id');
    $this->postJson($path, $input)->assertUnprocessable()->assertJsonValidationErrors('item_master_id');
    $second = $this->postJson($path, [...$input, 'item_master_id' => $otherItem->id])->assertCreated()->json('data.id');
    $this->patchJson($path.'/'.$second, ['item_master_id' => $item->id])->assertUnprocessable()->assertJsonValidationErrors('item_master_id');
    $this->postJson($path.'/bulk-delete', ['ids' => [$first, 99999]])->assertNotFound();
    $this->assertNotSoftDeleted('customer_prices', ['id' => $first]);

    $locked = CustomerPrice::query()->findOrFail($second);
    $flow = ApprovalFlow::query()->create(['module' => 'CustomerPrice', 'active' => false]);
    $approval = $locked->approval()->create([
        'approval_flow_id' => $flow->id, 'requested_by' => $owner->id, 'module' => 'CustomerPrice',
        'record_type' => CustomerPrice::class, 'record_id' => $second, 'approval_status' => 'pending',
    ]);
    $this->patchJson($path.'/'.$second, ['price' => 2])->assertUnprocessable();
    $approval->update(['approval_status' => 'approved']);
    $this->postJson($path.'/bulk-delete', ['ids' => [$first, $second]])->assertUnprocessable();
    $this->assertNotSoftDeleted('customer_prices', ['id' => $first]);

    grantPagePermissions($owner, 'OverrideApproval:CustomerPrice');
    $this->postJson($path.'/bulk-delete', ['ids' => [$first, $second]])->assertOk()->assertJsonPath('data.deleted', 2);
    $this->assertDatabaseHas('approval_overrides', ['model_type' => CustomerPrice::class, 'model_id' => $second, 'user_id' => $owner->id]);
    $this->assertSoftDeleted('customer_prices', ['id' => $first, 'deleted_by' => $owner->id]);
    $this->getJson($path.'/'.$first)->assertNotFound();
    $replacement = $this->postJson($path, [...$input, 'price' => 0, 'discount' => 100])->assertCreated()->json('data.id');
    expect($replacement)->not->toBe($first);
    $this->getJson($path)->assertOk()->assertJsonPath('total', 1);
});

it('uses exact variant prices for commercial documents and preserves stored line pricing', function () {
    $owner = grantPagePermissions(pricingRegisterOwner(), 'ViewAny:Quote', 'View:Quote', 'Create:Quote', 'Update:Quote',
        'ViewAny:SalesOrder', 'View:SalesOrder', 'Create:SalesOrder', 'ViewAny:SalesInvoice', 'View:SalesInvoice', 'Create:SalesInvoice');
    $account = reportingDoctor($owner, 'Commercial Clinic');
    $contact = reportingContact('Commercial', 'Buyer');
    $account->contactDetails()->attach($contact);
    $parent = reportingSampleItem('BASE-P', 'Boxed Product');
    $variant = reportingSampleItem('VAR-P', 'Variant Pack');
    $uom = UnitOfMeasurement::query()->create(['name' => 'Price Test Box', 'abbreviation' => 'PX']);
    $variant->update(['parent_id' => $parent->id, 'variant_name' => 'Box', 'selling_price' => 120, 'unit_of_measurement_id' => $uom->id]);
    $tax = Tax::query()->create(['name' => 'Price IGST', 'total_rate' => 18, 'is_active' => true]);
    $tax->components()->create(['type' => 'IGST', 'rate' => 18]);
    $variant->taxes()->attach($tax);
    SalesDocumentPreference::query()->create(['discount_level' => 'line_item']);
    Sanctum::actingAs($owner);
    $path = '/api/v1/customer-prices';

    $input = ['customer_id' => $account->id, 'item_master_id' => $parent->id, 'price' => 10];
    $this->postJson($path, $input)->assertCreated();
    $document = ['account_master_id' => $account->id, 'contact_detail_id' => $contact->id, 'date' => today()->toDateString(),
        'items' => [['item_master_id' => $variant->id, 'quantity' => 1.25]]];
    $catalogQuote = Quote::query()->findOrFail($this->postJson('/api/v1/quotes', $document)->assertCreated()->json('data.id'));
    expect((float) $catalogQuote->items()->sole()->unit_price)->toBe(120.0);

    $id = $this->postJson($path, [...$input, 'item_master_id' => $variant->id, 'price' => 80, 'discount' => 12.5])->assertCreated()->json('data.id');
    foreach (['quotes', 'sales-orders', 'sales-invoices'] as $register) {
        $result = $this->postJson('/api/v1/'.$register, $document)->assertCreated()
            ->assertJsonPath('data.items.0.unit', 'PX')->assertJsonPath('data.items.0.quantity', '1.250')
            ->assertJsonPath('data.tax_details.0.type', 'IGST');
        expect((float) $result->json('data.total'))->toBe(103.25)
            ->and((float) $result->json('data.items.0.unit_price'))->toBe(80.0);
    }
    $this->patchJson($path.'/'.$id, ['price' => 90])->assertOk();
    $catalogLine = $catalogQuote->items()->sole();
    $this->patchJson('/api/v1/quotes/'.$catalogQuote->id, ['items' => [['id' => $catalogLine->id, 'item_master_id' => $variant->id, 'quantity' => 2]]])->assertOk();
    expect((float) $catalogLine->fresh()->unit_price)->toBe(120.0);

    $fresh = $this->postJson('/api/v1/sales-orders', $document)->assertCreated();
    expect((float) $fresh->json('data.items.0.unit_price'))->toBe(90.0);
    $this->deleteJson($path.'/'.$id)->assertOk()->assertJsonPath('data.deleted', 1);
    $fallback = $this->postJson('/api/v1/sales-invoices', $document)->assertCreated();
    expect((float) $fallback->json('data.items.0.unit_price'))->toBe(120.0);

    $legacy = CustomerPrice::query()->create([...$input, 'item_master_id' => $variant->id, 'price' => null, 'discount' => 2]);
    $this->patchJson($path.'/'.$legacy->id, ['discount' => 5])->assertOk()
        ->assertJsonPath('data.price', null)->assertJsonPath('data.discount', '5.00');
    $discountOnly = $this->postJson('/api/v1/sales-invoices', $document)->assertCreated();
    expect((float) $discountOnly->json('data.items.0.unit_price'))->toBe(120.0)
        ->and((float) $discountOnly->json('data.total'))->toBe(168.15);
    $this->assertDatabaseCount('inventory_movements', 0);
});

it('uses scoped selectors and the shared create, edit, and delete path in Admin', function () {
    $owner = pricingRegisterOwner();
    $customer = reportingDoctor($owner, 'Visible Admin Clinic');
    $hidden = reportingDoctor(reportingUser('Other Admin Price Owner'), 'Hidden Admin Clinic');
    $item = reportingSampleItem('ADMIN-PRICE', 'Admin Price Item');
    $this->actingAs($owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $input = ['customer_id' => $customer->id, 'item_master_id' => $item->id, 'price' => 20, 'discount' => 10];

    livewire(CreateCustomerPrice::class)->fillForm([...$input, 'customer_id' => $hidden->id])
        ->call('create')->assertHasFormErrors(['customer_id']);
    livewire(CreateCustomerPrice::class)->fillForm($input)->call('create')->assertHasNoFormErrors();
    livewire(CreateCustomerPrice::class)->fillForm($input)->call('create')->assertHasFormErrors(['item_master_id' => 'unique']);
    $price = CustomerPrice::query()->sole();
    expect($price->customer_id)->toBe($customer->id);
    livewire(EditCustomerPrice::class, ['record' => $price->getRouteKey()])
        ->fillForm(['price' => 25])->call('save')->assertHasNoFormErrors();
    expect($price->fresh()->price)->toBe('25.00');
    livewire(ListCustomerPrices::class)->selectTableRecords([$price])
        ->callAction(TestAction::make('delete')->table()->bulk());
    $this->assertSoftDeleted('customer_prices', ['id' => $price->id]);
});
