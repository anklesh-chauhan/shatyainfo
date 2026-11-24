<?php

use App\Filament\Resources\InventoryMovements\Pages\ListInventoryMovements;
use App\Models\EmploymentDetail;
use App\Models\GoodsReceiptNote;
use App\Models\InventoryAdjustment;
use App\Models\InventoryMovement;
use App\Models\Organization;
use App\Models\OrganizationalUnit;
use App\Models\SampleIssue;
use App\Models\TypeMaster;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\StockLedger;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actor = reportingUser('Stock viewer');
    $this->outside = reportingUser('Outside stock viewer');
    foreach (['ViewAny:InventoryMovement', 'View:InventoryMovement', 'ViewAny:ItemMaster', 'View:ItemMaster', 'Update:ItemMaster', 'ViewAny:LocationMaster', 'View:LocationMaster', 'Update:LocationMaster', 'AccessAllRecords'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $this->actor->givePermissionTo(['ViewAny:InventoryMovement', 'View:InventoryMovement', 'ViewAny:ItemMaster', 'View:ItemMaster', 'Update:ItemMaster', 'ViewAny:LocationMaster', 'View:LocationMaster', 'Update:LocationMaster']);
    Sanctum::actingAs($this->outside);
    $this->privateItem = reportingSampleItem('PRIVATE', 'Private stock item');
    $this->privateLocation = reportingLocation('Private warehouse');
    $this->privateStock = InventoryMovement::query()->create(['item_master_id' => $this->privateItem->id, 'location_master_id' => $this->privateLocation->id, 'movement_type' => 'sample_issue_out', 'quantity_out' => 99, 'movement_at' => now(), 'reference_type' => SampleIssue::class, 'reference_id' => 99]);
    Sanctum::actingAs($this->actor);
    $this->item = reportingSampleItem('VISIBLE', 'Visible stock item');
    $this->location = reportingLocation('Visible warehouse');
    $this->stock = InventoryMovement::query()->create([
        'item_master_id' => $this->item->id, 'location_master_id' => $this->location->id,
        'movement_type' => 'grn_receipt', 'quantity_in' => '10.125', 'quantity_out' => '0.000', 'balance_after' => '10.125',
        'unit_cost' => '3.4567', 'total_value' => '35.0000', 'movement_at' => '2026-11-20 12:00:00',
        'reference_type' => GoodsReceiptNote::class, 'reference_id' => 1, 'remarks' => 'Opening delivery',
    ]);
    $this->path = '/api/v1/inventory-movements';
});

it('negotiates the ledger and limits rows and filter choices to readable movements', function () {
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertOk()->assertJsonFragment(['key' => 'g07-08', 'screen_key' => 'stock-ledger']);
    $this->getJson($this->path)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->stock->id)->assertJsonPath('data.0.quantity_in', '10.125')
        ->assertJsonPath('data.0.quantity_out', '0.000')->assertJsonPath('data.0.balance_after', '10.125')
        ->assertJsonPath('data.0.unit_cost', '3.4567')->assertJsonPath('data.0.reference_type', 'GoodsReceiptNote')
        ->assertJsonStructure(['fetched_at', 'movement_types']);
    foreach (['items' => $this->item->id, 'locations' => $this->location->id] as $type => $id) {
        $this->getJson($this->path.'/options/'.$type)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->getJson($this->path.'/options/'.$type.'?search=Private')->assertJsonCount(0, 'data');
    }
    $this->getJson($this->path.'?item_master_id='.$this->privateItem->id)->assertJsonCount(0, 'data');
    $this->getJson($this->path.'?location_master_id='.$this->privateLocation->id)->assertJsonCount(0, 'data');
    $this->getJson($this->path.'?search=Private')->assertJsonCount(0, 'data');
});

it('supports every posting type and inclusive dates without recalculating historical balances', function () {
    foreach (StockLedger::TYPES as $type => $label) {
        $movement = $this->stock->replicate()->fill(['movement_type' => $type, 'movement_at' => '2026-11-21 23:59:59', 'balance_after' => '37.125']);
        $movement->save();
        $this->getJson($this->path.'?movement_type='.$type.'&from=2026-11-21&until=2026-11-21')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $movement->id)
            ->assertJsonPath('data.0.movement_label', $label)->assertJsonPath('data.0.balance_after', '37.125');
    }
    $this->getJson($this->path.'?until=2026-11-20')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson($this->path.'?from=2026-11-22')->assertJsonCount(0, 'data');
    $this->getJson($this->path.'?search=Opening')->assertJsonCount(13, 'data');
    $this->getJson($this->path.'?search=sgip_distribution')->assertJsonCount(1, 'data');
    foreach (['movement_type=bogus', 'from=bad', 'until=2026-02-30', 'from=2026-11-22&until=2026-11-21', 'sort=reference_type', 'direction=sideways', 'page=0'] as $query) {
        $this->getJson($this->path.'?'.$query)->assertUnprocessable();
    }
});

it('sorts and pages deterministically and keeps archived master labels', function () {
    for ($index = 0; $index < 31; $index++) {
        $movement = $this->stock->replicate();
        $movement->save();
    }
    $this->getJson($this->path.'?sort=movement_at&direction=asc&page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('last_page', 2);
    foreach (['item_code', 'item_name', 'location', 'balance_after', 'movement_type', 'quantity_in', 'quantity_out', 'unit_cost', 'total_value'] as $sort) {
        $this->getJson($this->path.'?sort='.$sort.'&direction=asc')->assertOk()->assertJsonPath('data.0.id', $this->stock->id);
    }
    $this->item->delete();
    $this->location->delete();
    $this->getJson($this->path)->assertJsonPath('data.0.item_name', 'Visible stock item')->assertJsonPath('data.0.item_archived', true)->assertJsonPath('data.0.location_archived', true);
    $this->getJson($this->path.'/options/items?search=Visible')->assertJsonPath('data.0.label', 'Visible stock item (Archived)');
    $this->getJson($this->path.'/options/locations?search=Visible')->assertJsonPath('data.0.label', 'Visible warehouse (Archived)');
});

it('does not infer movement ownership from a shared location and enforces both read grants', function () {
    $movement = $this->privateStock->replicate()->fill(['location_master_id' => $this->location->id]);
    $movement->save();
    $movement->forceFill(['created_by' => $this->outside->id])->saveQuietly();
    $this->getJson($this->path)->assertJsonCount(1, 'data');
    $this->actor->givePermissionTo('AccessAllRecords');
    $this->getJson($this->path)->assertJsonCount(3, 'data');
    $this->actor->revokePermissionTo('View:InventoryMovement');
    $this->getJson($this->path)->assertForbidden();
    $this->getJson($this->path.'/options/items')->assertForbidden();
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertJsonMissing(['key' => 'g07-08']);
    $this->actingAs($this->actor, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(ListInventoryMovements::class)->assertForbidden();
});

it('denies navigation and direct reads when inventory is disabled', function () {
    Organization::query()->create(['name' => 'Disabled ledger company', 'metadata' => ['company_setup' => ['organization_saved' => true]]]);
    $this->actor->givePermissionTo('AccessAllRecords');
    $this->getJson($this->path)->assertForbidden();
    $this->getJson($this->path.'/options/locations')->assertForbidden();
    $this->getJson('/api/v1/mobile-navigation?destinations=standalone')->assertJsonMissing(['key' => 'g07-08']);
    $this->actingAs($this->actor, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(ListInventoryMovements::class)->assertForbidden();
});

it('shares Admin visibility and complete movement filters without mutation actions', function () {
    $ownedAtHiddenLocation = $this->stock->replicate()->fill(['location_master_id' => $this->privateLocation->id]);
    $ownedAtHiddenLocation->save();
    $this->actingAs($this->actor, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(ListInventoryMovements::class)->assertSuccessful()->assertCanSeeTableRecords([$this->stock])
        ->assertCanNotSeeTableRecords([$this->privateStock, $ownedAtHiddenLocation])
        ->filterTable('movement_type', 'grn_receipt')->assertCanSeeTableRecords([$this->stock]);
    $this->stock->update(['movement_type' => 'sgip_distribution']);
    Livewire::test(ListInventoryMovements::class)->filterTable('movement_type', 'sgip_distribution')->assertCanSeeTableRecords([$this->stock]);
    $this->postJson($this->path, ['quantity_in' => 999])->assertStatus(405);
    $this->patchJson($this->path.'/'.$this->stock->id, ['balance_after' => 999])->assertNotFound();
    $this->deleteJson($this->path.'/'.$this->stock->id)->assertNotFound();
    expect($this->stock->refresh()->balance_after)->toBe('10.125');
});

it('reads actual posted adjustment history once without requiring access to its source document', function () {
    $adjustment = InventoryAdjustment::query()->create([
        'item_master_id' => $this->item->id, 'location_master_id' => $this->location->id,
        'adjustment_type' => 'receipt', 'quantity' => '2.125', 'unit_cost' => '4.5678', 'reason' => 'Ledger posting',
    ]);
    $service = app(InventoryService::class);
    $service->postAdjustment($adjustment, $this->actor->id);
    $service->postAdjustment($adjustment, $this->actor->id);
    $this->getJson($this->path.'?movement_type=adjustment_receipt')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reference_type', 'InventoryAdjustment')->assertJsonPath('data.0.reference_id', $adjustment->id)
        ->assertJsonPath('data.0.quantity_in', '2.125')->assertJsonPath('data.0.balance_after', '2.125')->assertJsonPath('data.0.unit_cost', '4.5678');
});
it('uses creator OU grants while retaining the location visibility intersection', function () {
    $ownedAtHiddenLocation = $this->stock->replicate()->fill(['location_master_id' => $this->privateLocation->id]);
    $ownedAtHiddenLocation->save();
    $this->getJson($this->path.'?location_master_id='.$this->privateLocation->id)->assertOk()->assertJsonCount(0, 'data');
    $type = TypeMaster::query()->firstOrCreate(['name' => 'Division', 'typeable_type' => OrganizationalUnit::class]);
    $unit = OrganizationalUnit::query()->create(['name' => 'Stock division', 'code' => 'STOCK-DIV', 'type_master_id' => $type->id]);
    foreach ([$this->actor, $this->outside] as $user) {
        $employment = EmploymentDetail::query()->firstOrCreate(['employee_id' => $user->employee->id]);
        $employment->organizationalUnits()->attach($unit->id, ['is_primary' => true]);
    }
    foreach (['ViewOwnOU:InventoryMovement', 'ViewOwnOU:LocationMaster'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $this->actor->givePermissionTo($permission);
    }
    $this->actor->unsetRelation('employee');
    $this->getJson($this->path.'?location_master_id='.$this->privateLocation->id)->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonFragment(['id' => $ownedAtHiddenLocation->id])->assertJsonFragment(['id' => $this->privateStock->id]);
});

it('requires the list grant even when detail and bypass grants are retained', function () {
    $this->actor->givePermissionTo('AccessAllRecords');
    $this->actor->revokePermissionTo('ViewAny:InventoryMovement');
    $this->getJson($this->path)->assertForbidden();
    $this->getJson($this->path.'/options/locations')->assertForbidden();
});
