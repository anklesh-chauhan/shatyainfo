<?php

use App\Filament\Resources\ItemMasters\Pages\CreateItemMaster;
use App\Filament\Resources\ItemMasters\Pages\EditItemMaster;
use App\Filament\Resources\ItemMasters\RelationManagers\LeadsRelationManager;
use App\Filament\Resources\ItemMasters\RelationManagers\SuppliersRelationManager;
use App\Filament\Resources\ItemMasters\RelationManagers\VariantsRelationManager;
use App\Models\AccountMaster;
use App\Models\ApprovalFlow;
use App\Models\ApprovalSetting;
use App\Models\Category;
use App\Models\InventoryStock;
use App\Models\ItemMaster;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\Tax;
use App\Models\TypeMaster;
use App\Services\Inventory\ItemRegister;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actor = reportingUser('Item maintainer');
    $this->outside = reportingUser('Other item maintainer');
    $permissions = [];
    foreach (['ViewAny', 'View', 'Create', 'Update', 'Delete', 'DeleteAny', 'Restore', 'RestoreAny', 'ForceDelete', 'ForceDeleteAny'] as $ability) {
        $permissions[] = $ability.':ItemMaster';
    }
    foreach (['AccountMaster', 'Lead', 'InventoryStock'] as $model) {
        foreach (['ViewAny', 'View', 'Update', 'Delete'] as $ability) {
            $permissions[] = $ability.':'.$model;
        }
    }
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $this->actor->givePermissionTo($permissions);
    $this->outside->givePermissionTo($permissions);
    Sanctum::actingAs($this->outside);
    $this->hidden = reportingSampleItem('HIDDEN', 'Private item');
    $this->hiddenCategory = Category::query()->create(['name' => 'Private category']);
    Sanctum::actingAs($this->actor);
    $this->category = Category::query()->create(['name' => 'Medicines']);
    $this->tax = Tax::query()->create(['name' => 'Test GST', 'total_rate' => 5, 'is_active' => true]);
    $this->path = '/api/v1/item-masters';
    $this->payload = ['item_name' => 'Test medicine', 'item_type' => 'sample', 'category_id' => $this->category->id,
        'sku' => 'MED-1', 'selling_price' => '12.50', 'tax_ids' => [$this->tax->id]];
});

it('negotiates scoped item management without changing the field capture catalog', function () {
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertOk()->assertJsonFragment(['key' => 'g07-01', 'screen_key' => 'item-masters']);
    $this->getJson($this->path)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($this->path.'/'.$this->hidden->id)->assertNotFound();
    $this->getJson($this->path.'/options/categories')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->category->id);
    $this->postJson($this->path, [...$this->payload, 'category_id' => $this->hiddenCategory->id])->assertUnprocessable();
    $headers = ['Idempotency-Key' => (string) Str::uuid()];
    $id = $this->postJson($this->path, $this->payload, $headers)->assertCreated()->json('data.id');
    $this->postJson($this->path, $this->payload, $headers)->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.id', $id);
    $this->getJson($this->path.'?search=medicine&type=sample')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/items')->assertOk()->assertJsonFragment(['id' => $this->hidden->id]);
});

it('validates SKU tax and price changes and rejects stale edits and actions', function () {
    $id = $this->postJson($this->path, $this->payload)->assertCreated()->json('data.id');
    $path = $this->path.'/'.$id;
    $before = $this->getJson($path)->assertOk()->json('data');
    $this->patchJson($path, [...$this->payload, 'revision' => $before['revision'], 'selling_price' => '13.25'])->assertOk()->assertJsonPath('data.selling_price', '13.25');
    $this->patchJson($path, [...$this->payload, 'revision' => $before['revision']])->assertConflict();
    $this->postJson($path.'/actions/delete', ['revision' => $before['revision']])->assertConflict();
    $this->postJson($this->path, $this->payload)->assertUnprocessable()->assertJsonValidationErrors('sku');
    $this->postJson($this->path, [...$this->payload, 'sku' => null, 'selling_price' => '-1'])->assertUnprocessable();
    $this->postJson($this->path, [...$this->payload, 'sku' => null, 'tax_ids' => [999999]])->assertUnprocessable();
});

it('creates associates dissociates and trashes variants without cycles or cross-parent writes', function () {
    $parent = $this->postJson($this->path, $this->payload)->assertCreated()->json('data.id');
    $path = $this->path.'/'.$parent;
    $child = $this->postJson($path.'/variants', [...$this->payload, 'sku' => 'VAR-1', 'variant_name' => '10 tablets', 'item_name' => 'Injected name'])->assertCreated()->json('data.id');
    $this->getJson($this->path.'/'.$child)->assertJsonPath('data.item_name', 'Test medicine')->assertJsonPath('data.parent_id', $parent);
    $this->getJson($path.'/related/variants')->assertOk()->assertJsonPath('data.0.id', $child);
    $this->postJson($path.'/related/variants/attach', ['ids' => [$parent], 'revision' => $this->getJson($path)->json('data.revision')])->assertNotFound();
    $this->postJson($this->path.'/'.$child.'/actions/dissociate', ['revision' => $this->getJson($this->path.'/'.$child)->json('data.revision')])->assertOk();
    $this->postJson($path.'/related/variants/attach', ['ids' => [$child], 'revision' => $this->getJson($path)->json('data.revision')])->assertOk();
    $this->postJson($this->path.'/'.$child.'/actions/delete', ['revision' => $this->getJson($this->path.'/'.$child)->json('data.revision')])->assertOk();
    $this->getJson($path.'/related/variants?trash=trashed')->assertJsonPath('data.0.id', $child);
    $this->postJson($this->path.'/'.$child.'/actions/restore', ['revision' => $this->getJson($this->path.'/'.$child)->json('data.revision')])->assertOk();
    $this->postJson($this->path.'/'.$child.'/actions/delete', ['revision' => $this->getJson($this->path.'/'.$child)->json('data.revision')])->assertOk();
    $this->postJson($this->path.'/'.$child.'/actions/force-delete', ['revision' => $this->getJson($this->path.'/'.$child)->json('data.revision')])->assertOk();
    $this->getJson($this->path.'/'.$child)->assertNotFound();
});

it('scopes stock balances and lead supplier relations and validates pivot values', function () {
    $id = $this->postJson($this->path, $this->payload)->assertCreated()->json('data.id');
    $path = $this->path.'/'.$id;
    $location = reportingLocation('Owned store');
    InventoryStock::query()->create(['item_master_id' => $id, 'location_master_id' => $location->id, 'quantity_on_hand' => 5]);
    $this->getJson($path.'/related/stocks')->assertOk()->assertJsonCount(1, 'data');
    $this->actor->revokePermissionTo('View:InventoryStock');
    $this->getJson($path.'/related/stocks')->assertForbidden();
    $supplier = reportingDoctor($this->actor, 'Supplier account');
    $this->postJson($path.'/related/suppliers/attach', ['ids' => [$supplier->id], 'revision' => $this->getJson($path)->json('data.revision')])->assertOk();
    $this->getJson($path.'/related/suppliers')->assertJsonPath('data.0.id', $supplier->id);
    $status = reportingLeadStatus('Item lead');
    $lead = Lead::query()->create(['owner_id' => $this->actor->id, 'transaction_date' => today(), 'status_id' => $status->id, 'status_type' => LeadStatus::class]);
    $this->postJson($path.'/related/leads/attach', ['ids' => [$lead->id], 'quantity' => 2, 'price' => '15.25', 'revision' => $this->getJson($path)->json('data.revision')])->assertOk();
    $this->getJson($path.'/related/leads')->assertJsonPath('data.0.quantity', 2)->assertJsonPath('data.0.price', '15.25');
    $this->postJson($path.'/related/leads/update', ['ids' => [$lead->id], 'quantity' => -1, 'revision' => $this->getJson($path)->json('data.revision')])->assertUnprocessable();
    $this->postJson($path.'/related/leads/detach', ['ids' => [$lead->id], 'revision' => $this->getJson($path)->json('data.revision')])->assertOk();
});

it('requires explicit bulk and record permissions and keeps mixed bulk selections atomic', function () {
    $id = $this->postJson($this->path, $this->payload)->assertCreated()->json('data.id');
    $this->postJson($this->path.'/records/delete', ['ids' => [$id, $this->hidden->id]])->assertNotFound();
    expect(ItemMaster::find($id))->not->toBeNull();
    $this->actor->revokePermissionTo('DeleteAny:ItemMaster');
    $this->postJson($this->path.'/records/delete', ['ids' => [$id]])->assertForbidden();
    $this->actor->revokePermissionTo('Update:ItemMaster');
    $path = $this->path.'/'.$id;
    $this->patchJson($path, [...$this->payload, 'revision' => $this->getJson($path)->json('data.revision')])->assertForbidden();
    $this->actor->revokePermissionTo('ViewAny:ItemMaster');
    $this->getJson($this->path)->assertForbidden();
});

it('uses the shared item editor for Admin saves and stale action checks', function () {
    $this->actingAs($this->actor, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(CreateItemMaster::class)->fillForm($this->payload)->call('create')->assertHasNoFormErrors();
    $item = ItemMaster::query()->where('sku', 'MED-1')->sole();
    $editor = Livewire::test(EditItemMaster::class, ['record' => $item->id]);
    $item->update(['selling_price' => '17.00']);
    $editor->callAction('delete')->assertNotified('The item changed. Reload it before continuing.');
    expect($item->refresh()->trashed())->toBeFalse();
});

it('locks pending and approved item changes and records an authorized override', function () {
    $id = $this->postJson($this->path, $this->payload)->assertCreated()->json('data.id');
    $item = ItemMaster::findOrFail($id);
    $flow = ApprovalFlow::query()->create(['module' => 'ItemMaster', 'active' => false]);
    $approval = $item->approval()->create(['approval_flow_id' => $flow->id, 'requested_by' => $this->actor->id,
        'module' => 'ItemMaster', 'record_type' => ItemMaster::class, 'record_id' => $id, 'approval_status' => 'pending']);
    $path = $this->path.'/'.$id;
    $this->getJson($path)->assertJsonPath('data.can_edit', false);
    $this->postJson($path.'/actions/delete', ['revision' => $this->getJson($path)->json('data.revision')])->assertForbidden();
    $approval->update(['approval_status' => 'approved']);
    $this->patchJson($path, [...$this->payload, 'revision' => $this->getJson($path)->json('data.revision')])->assertForbidden();
    Permission::findOrCreate('OverrideApproval:ItemMaster', 'web');
    $this->actor->givePermissionTo('OverrideApproval:ItemMaster');
    $this->patchJson($path, [...$this->payload, 'selling_price' => '99.00', 'revision' => $this->getJson($path)->json('data.revision')])->assertOk();
    $this->assertDatabaseHas('approval_overrides', ['model_type' => ItemMaster::class, 'model_id' => $id, 'user_id' => $this->actor->id]);
});

it('preserves the unsupported item approval setting without exposing a submit action', function () {
    $id = $this->postJson($this->path, $this->payload)->assertCreated()->json('data.id');
    $data = $this->getJson($this->path.'/'.$id)->assertOk()->json('data');
    expect($data['actions'])->not->toContain('submit');
    expect(ApprovalSetting::eligibleModuleOptions())->not->toHaveKey(ItemMaster::class);
    $this->postJson($this->path.'/'.$id.'/actions/submit', ['revision' => $data['revision']])->assertForbidden();
});

it('uses shared variant relation creation and editing in Admin', function () {
    $this->actingAs($this->actor, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $parent = app(ItemRegister::class)->save($this->actor, null, $this->payload);
    $relation = Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $parent, 'pageClass' => EditItemMaster::class]);
    $relation->callAction(TestAction::make('create')->table(), data: [...$this->payload, 'sku' => 'ADMINVAR', 'variant_name' => 'Box'])
        ->assertHasNoFormErrors();
    $child = $parent->variants()->sole();
    expect($child->item_name)->toBe($parent->item_name);
    Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $parent, 'pageClass' => EditItemMaster::class])
        ->callAction(TestAction::make('edit')->table($child), data: ['selling_price' => '19.50'])
        ->assertHasNoFormErrors();
    expect($child->refresh()->selling_price)->toBe('19.50');
});

it('shares Admin supplier creation and lead pivot editing with scoped relation writes', function () {
    Permission::findOrCreate('Create:AccountMaster', 'web');
    $this->actor->givePermissionTo('Create:AccountMaster');
    $this->actingAs($this->actor, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $parent = app(ItemRegister::class)->save($this->actor, null, $this->payload);
    $type = TypeMaster::query()->firstOrCreate(['name' => 'Supplier', 'typeable_type' => AccountMaster::class]);
    Livewire::test(SuppliersRelationManager::class,
        ['ownerRecord' => $parent, 'pageClass' => EditItemMaster::class])
        ->callAction(TestAction::make('create')->table(), data: ['name' => 'Admin supplier', 'parent_type_id' => $type->id, 'type_master_id' => $type->id])
        ->assertHasNoFormErrors();
    expect($parent->suppliers()->sole()->name)->toBe('Admin supplier');
    $status = reportingLeadStatus('Admin related lead');
    $lead = Lead::query()->create(['owner_id' => $this->actor->id, 'transaction_date' => today(), 'status_id' => $status->id, 'status_type' => LeadStatus::class]);
    Livewire::test(LeadsRelationManager::class,
        ['ownerRecord' => $parent->fresh(), 'pageClass' => EditItemMaster::class])
        ->callAction(TestAction::make('attach')->table(), data: ['ids' => [$lead->id], 'quantity' => 2, 'price' => '5.50'])
        ->assertHasNoFormErrors();
    expect($parent->leads()->sole()->pivot->quantity)->toBe(2);
});
