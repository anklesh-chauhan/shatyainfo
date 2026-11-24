<?php

use App\Filament\Resources\InventoryStocks\Pages\ListInventoryStocks;
use App\Filament\Resources\ItemMasters\Pages\EditItemMaster;
use App\Filament\Resources\ItemMasters\RelationManagers\LocationsRelationManager;
use App\Filament\Resources\LocationMasters\Pages\EditLocationMaster;
use App\Filament\Resources\LocationMasters\RelationManagers\ItemsRelationManager;
use App\Models\EmploymentDetail;
use App\Models\InventoryStock;
use App\Models\Organization;
use App\Models\OrganizationalUnit;
use App\Models\TypeMaster;
use App\Services\Inventory\InventoryService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actor = reportingUser('Stock viewer');
    $this->outside = reportingUser('Outside stock viewer');
    foreach (['ViewAny:InventoryStock', 'View:InventoryStock', 'ViewAny:ItemMaster', 'View:ItemMaster', 'Update:ItemMaster', 'ViewAny:LocationMaster', 'View:LocationMaster', 'Update:LocationMaster', 'AccessAllRecords'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $this->actor->givePermissionTo(['ViewAny:InventoryStock', 'View:InventoryStock', 'ViewAny:ItemMaster', 'View:ItemMaster', 'Update:ItemMaster', 'ViewAny:LocationMaster', 'View:LocationMaster', 'Update:LocationMaster']);
    Sanctum::actingAs($this->outside);
    $this->privateItem = reportingSampleItem('PRIVATE', 'Private stock item');
    $this->privateLocation = reportingLocation('Private warehouse');
    $this->privateStock = InventoryStock::query()->create(['item_master_id' => $this->privateItem->id, 'location_master_id' => $this->privateLocation->id, 'quantity_on_hand' => 99]);
    Sanctum::actingAs($this->actor);
    $this->item = reportingSampleItem('VISIBLE', 'Visible stock item');
    $this->location = reportingLocation('Visible warehouse');
    $this->stock = InventoryStock::query()->create([
        'item_master_id' => $this->item->id, 'location_master_id' => $this->location->id,
        'quantity_on_hand' => '10.125', 'quantity_reserved' => '2.000', 'quantity_available' => '8.125',
        'average_cost' => '3.4567', 'last_movement_at' => now()->subMinute(),
    ]);
    $this->path = '/api/v1/inventory-stocks';
});

it('negotiates read-only stock and exposes only balances and choices in scope', function () {
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertOk()->assertJsonFragment(['key' => 'g07-02', 'screen_key' => 'stock-on-hand']);
    $this->getJson($this->path)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->stock->id)->assertJsonPath('data.0.quantity_on_hand', '10.125')
        ->assertJsonPath('data.0.quantity_reserved', '2.000')->assertJsonPath('data.0.quantity_available', '8.125')
        ->assertJsonPath('data.0.average_cost', '3.4567')->assertJsonStructure(['fetched_at']);
    $this->getJson($this->path.'/options/items')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->item->id);
    $this->getJson($this->path.'/options/locations')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->location->id);
    $this->getJson($this->path.'?item_master_id='.$this->privateItem->id)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($this->path.'?location_master_id='.$this->privateLocation->id)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($this->path.'/options/items?search=Private')->assertJsonCount(0, 'data');
});

it('searches filters sorts and pages deterministically without leaking scope through OR search', function () {
    $laterItem = reportingSampleItem('LATER', 'Zinc pack');
    InventoryStock::query()->create(['item_master_id' => $laterItem->id, 'location_master_id' => $this->location->id, 'quantity_on_hand' => 20, 'last_movement_at' => null]);
    $this->getJson($this->path.'?search=warehouse')->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $this->stock->id);
    $this->getJson($this->path.'?search=Private')->assertJsonCount(0, 'data');
    $this->getJson($this->path.'?item_master_id='.$laterItem->id)->assertJsonCount(1, 'data');
    $this->getJson($this->path.'?sort=item_name&direction=desc')->assertJsonPath('data.0.item_name', 'Zinc pack');
    $this->getJson($this->path.'?sort=quantity_on_hand&direction=asc')->assertJsonPath('data.0.id', $this->stock->id);
    $this->getJson($this->path.'?sort=location&direction=asc')->assertOk();
    $this->getJson($this->path.'?sort=invalid')->assertUnprocessable()->assertJsonValidationErrors('sort');
    $this->getJson($this->path.'?page=0')->assertUnprocessable();
    $this->getJson($this->path.'?direction=sideways')->assertUnprocessable();
    for ($index = 0; $index < 30; $index++) {
        $item = reportingSampleItem('PAGE'.$index, 'Page pack '.$index);
        InventoryStock::query()->create(['item_master_id' => $item->id, 'location_master_id' => $this->location->id]);
    }
    $this->getJson($this->path.'?page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('last_page', 2);
});

it('does not infer ownership of system or other creators balances from a shared location', function () {
    $item = reportingSampleItem('SYSTEM', 'System balance');
    auth()->forgetGuards();
    $system = InventoryStock::query()->create(['item_master_id' => $item->id, 'location_master_id' => $this->location->id]);
    $system->forceFill(['created_by' => null, 'updated_by' => null])->saveQuietly();
    Sanctum::actingAs($this->actor);
    $this->getJson($this->path.'?item_master_id='.$item->id)->assertJsonCount(0, 'data');
    $this->actor->givePermissionTo('AccessAllRecords');
    $this->getJson($this->path.'?item_master_id='.$item->id)->assertJsonPath('data.0.id', $system->id);
    $this->actor->revokePermissionTo('View:InventoryStock');
    $this->getJson($this->path)->assertForbidden();
    $this->getJson($this->path.'/options/items')->assertForbidden();
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertJsonMissing(['key' => 'g07-02']);
});

it('denies direct routes and related stock reads when inventory is disabled', function () {
    Organization::query()->create(['name' => 'Disabled stock company', 'metadata' => ['company_setup' => ['organization_saved' => true]]]);
    $this->actor->givePermissionTo('AccessAllRecords');
    $this->getJson($this->path)->assertForbidden();
    $this->getJson($this->path.'/options/locations')->assertForbidden();
    $this->getJson('/api/v1/item-masters/'.$this->item->id.'/related/stocks')->assertForbidden();
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertJsonMissing(['key' => 'g07-02']);
    $this->actingAs($this->actor, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(ListInventoryStocks::class)->assertForbidden();
});

it('shares the visible rows between Admin register and item and location stock relations', function () {
    $this->actingAs($this->actor, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(ListInventoryStocks::class)->assertSuccessful()->assertCanSeeTableRecords([$this->stock])->assertCanNotSeeTableRecords([$this->privateStock])
        ->filterTable('item_master_id', $this->item->id)->assertCanSeeTableRecords([$this->stock]);
    Livewire::test(LocationsRelationManager::class, ['ownerRecord' => $this->item, 'pageClass' => EditItemMaster::class])
        ->assertCanSeeTableRecords([$this->stock])->assertCanNotSeeTableRecords([$this->privateStock]);
    Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $this->location, 'pageClass' => EditLocationMaster::class])
        ->assertCanSeeTableRecords([$this->stock]);
    $this->actor->revokePermissionTo('View:InventoryStock');
    Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $this->location, 'pageClass' => EditLocationMaster::class])
        ->assertCanNotSeeTableRecords([$this->stock]);
});

it('keeps archived master labels and reads posting-created balances without permitting writes', function () {
    $newItem = reportingSampleItem('POSTED', 'Posted stock');
    $service = app(InventoryService::class);
    $balance = $service->stockFor($newItem->id, $this->location->id);
    $this->getJson($this->path.'?item_master_id='.$newItem->id)->assertJsonPath('data.0.id', $balance->id);
    $newItem->delete();
    $this->getJson($this->path.'?item_master_id='.$newItem->id)->assertJsonPath('data.0.item_name', 'Posted stock')->assertJsonPath('data.0.item_archived', true);
    $this->getJson($this->path.'/options/items?search=Posted')->assertJsonPath('data.0.label', 'Posted stock (Archived)');
    $this->postJson($this->path, ['quantity_on_hand' => 999])->assertStatus(405);
    $this->patchJson($this->path.'/'.$balance->id, ['quantity_on_hand' => 999])->assertNotFound();
    expect((float) $balance->refresh()->quantity_on_hand)->toBe(0.0);
});

it('uses creator OU grants while retaining the location visibility intersection', function () {
    $ownedAtHiddenLocation = InventoryStock::query()->create(['item_master_id' => $this->item->id, 'location_master_id' => $this->privateLocation->id]);
    $this->getJson($this->path.'?location_master_id='.$this->privateLocation->id)->assertOk()->assertJsonCount(0, 'data');
    $type = TypeMaster::query()->firstOrCreate(['name' => 'Division', 'typeable_type' => OrganizationalUnit::class]);
    $unit = OrganizationalUnit::query()->create(['name' => 'Stock division', 'code' => 'STOCK-DIV', 'type_master_id' => $type->id]);
    foreach ([$this->actor, $this->outside] as $user) {
        $employment = EmploymentDetail::query()->firstOrCreate(['employee_id' => $user->employee->id]);
        $employment->organizationalUnits()->attach($unit->id, ['is_primary' => true]);
    }
    foreach (['ViewOwnOU:InventoryStock', 'ViewOwnOU:LocationMaster'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $this->actor->givePermissionTo($permission);
    }
    $this->actor->unsetRelation('employee');
    $this->getJson($this->path.'?location_master_id='.$this->privateLocation->id)->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonFragment(['id' => $ownedAtHiddenLocation->id])->assertJsonFragment(['id' => $this->privateStock->id]);
});
