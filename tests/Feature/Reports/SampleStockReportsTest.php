<?php

use App\Filament\Pages\DoctorSampleLedger as LegacyDoctorSampleLedger;
use App\Filament\Pages\Reports\DoctorSampleLedger;
use App\Filament\Pages\Reports\ReportsOverview;
use App\Filament\Pages\Reports\SgipCompliance;
use App\Filament\Pages\Reports\StockMovementRegister;
use App\Models\InventoryMovement;
use App\Models\SgipDistribution;
use App\Models\SgipDistributionItem;
use App\Models\SgipLimit;
use App\Models\SgipViolation;
use App\Services\Reports\DoctorSampleLedgerQuery;
use App\Services\Reports\SgipComplianceQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

it('lists visible sample lines on the doctor sample ledger', function () {
    $territory = reportingTerritory('Sample North');
    $rep = reportingUser('SampleRep');
    $other = reportingUser('HiddenRep');

    $this->actingAs(grantPagePermissions($rep, 'View:DoctorSampleLedger'));

    $visibleDoctor = reportingDoctor($rep, 'Dr Visible Sample');
    $hiddenDoctor = reportingDoctor($other, 'Dr Hidden Sample');
    $item = reportingSampleItem('SMP', 'Amoxicillin Sample');

    $ownDistribution = SgipDistribution::forceCreate([
        'user_id' => $rep->id,
        'account_master_id' => $visibleDoctor->id,
        'territory_id' => $territory->id,
        'visit_date' => today(),
        'approval_status' => 'submitted',
        'created_by' => $rep->id,
        'updated_by' => $rep->id,
    ]);

    $otherDistribution = SgipDistribution::forceCreate([
        'user_id' => $other->id,
        'account_master_id' => $hiddenDoctor->id,
        'territory_id' => $territory->id,
        'visit_date' => today(),
        'approval_status' => 'submitted',
        'created_by' => $other->id,
        'updated_by' => $other->id,
    ]);

    SgipDistributionItem::forceCreate([
        'sgip_distribution_id' => $ownDistribution->id,
        'item_master_id' => $item->id,
        'quantity' => 4,
        'unit_value' => 10,
        'created_by' => $rep->id,
        'updated_by' => $rep->id,
    ]);

    SgipDistributionItem::forceCreate([
        'sgip_distribution_id' => $otherDistribution->id,
        'item_master_id' => $item->id,
        'quantity' => 9,
        'unit_value' => 10,
        'created_by' => $other->id,
        'updated_by' => $other->id,
    ]);

    livewire(DoctorSampleLedger::class)
        ->assertSuccessful()
        ->assertSee('Dr Visible Sample')
        ->assertSee('Amoxicillin Sample')
        ->assertDontSee('Dr Hidden Sample');
});

it('lists visible SGIP violations', function () {
    $territory = reportingTerritory('Compliance West');
    $rep = reportingUser('ComplianceRep');
    $other = reportingUser('OtherCompliance');

    $this->actingAs(grantPagePermissions($rep, 'View:SgipCompliance'));

    $doctor = reportingDoctor($rep, 'Dr Quota Breach');
    $hiddenDoctor = reportingDoctor($other, 'Dr Hidden Quota');
    $item = reportingSampleItem('CMP', 'Campaign Sample');

    $ownDistribution = SgipDistribution::forceCreate([
        'user_id' => $rep->id,
        'account_master_id' => $doctor->id,
        'territory_id' => $territory->id,
        'visit_date' => today(),
        'approval_status' => 'submitted',
        'created_by' => $rep->id,
        'updated_by' => $rep->id,
    ]);

    $otherDistribution = SgipDistribution::forceCreate([
        'user_id' => $other->id,
        'account_master_id' => $hiddenDoctor->id,
        'territory_id' => $territory->id,
        'visit_date' => today(),
        'approval_status' => 'submitted',
        'created_by' => $other->id,
        'updated_by' => $other->id,
    ]);

    SgipDistributionItem::forceCreate([
        'sgip_distribution_id' => $ownDistribution->id,
        'item_master_id' => $item->id,
        'quantity' => 12,
        'unit_value' => 5,
        'created_by' => $rep->id,
        'updated_by' => $rep->id,
    ]);

    $limit = SgipLimit::query()->create([
        'applies_to' => 'global',
        'item_type' => 'sample',
        'period' => 'monthly',
        'max_quantity' => 2,
        'created_by' => $rep->id,
        'updated_by' => $rep->id,
    ]);

    SgipViolation::forceCreate([
        'sgip_distribution_id' => $ownDistribution->id,
        'sgip_limit_id' => $limit->id,
        'violation_type' => 'quantity',
        'allowed_value' => 2,
        'actual_value' => 12,
        'created_by' => $rep->id,
        'updated_by' => $rep->id,
    ]);

    SgipViolation::forceCreate([
        'sgip_distribution_id' => $otherDistribution->id,
        'violation_type' => 'campaign_quota',
        'allowed_value' => 1,
        'actual_value' => 8,
        'created_by' => $other->id,
        'updated_by' => $other->id,
    ]);

    livewire(SgipCompliance::class)
        ->assertSuccessful()
        ->assertSee('Dr Quota Breach')
        ->assertSee('Quantity')
        ->assertDontSee('Dr Hidden Quota');
});

it('lists own stock movements', function () {
    $rep = reportingUser('StockRep');
    $other = reportingUser('OtherStock');

    $this->actingAs(grantPagePermissions($rep, 'View:StockMovementRegister'));

    $item = reportingSampleItem('STK', 'Visible Stock Item');
    $hiddenItem = reportingSampleItem('HID', 'Hidden Stock Item');
    $location = reportingLocation('Field Warehouse');

    InventoryMovement::forceCreate([
        'item_master_id' => $item->id,
        'location_master_id' => $location->id,
        'movement_type' => 'sgip_distribution',
        'quantity_in' => 0,
        'quantity_out' => 3,
        'balance_after' => 7,
        'movement_at' => now(),
        'created_by' => $rep->id,
        'updated_by' => $rep->id,
    ]);

    InventoryMovement::forceCreate([
        'item_master_id' => $hiddenItem->id,
        'location_master_id' => $location->id,
        'movement_type' => 'sgip_distribution',
        'quantity_in' => 0,
        'quantity_out' => 5,
        'balance_after' => 1,
        'movement_at' => now(),
        'created_by' => $other->id,
        'updated_by' => $other->id,
    ]);

    livewire(StockMovementRegister::class)
        ->assertSuccessful()
        ->assertSee('Visible Stock Item')
        ->assertSee('Field Warehouse')
        ->assertDontSee('Hidden Stock Item');
});

it('exposes phase 2 reports on the overview catalog', function () {
    $user = reportingUser('CatalogUser');

    $this->actingAs(grantPagePermissions($user, 'View:ReportsOverview'));

    livewire(ReportsOverview::class)
        ->assertSuccessful()
        ->assertSee('SGIP compliance')
        ->assertSee('Stock movements')
        ->assertSee('Stock on hand');
});

it('hides the legacy marketing doctor sample ledger from navigation', function () {
    expect(LegacyDoctorSampleLedger::shouldRegisterNavigation())->toBeFalse();
});

it('scopes sample ledger lines to visible submitted distributions', function () {
    $territory = reportingTerritory('Query North');
    $rep = reportingUser('QueryRep');
    $other = reportingUser('QueryOther');

    $this->actingAs($rep);

    $ownDoctor = reportingDoctor($rep, 'Dr Query Visible');
    $otherDoctor = reportingDoctor($other, 'Dr Query Hidden');
    $item = reportingSampleItem('QRY', 'Query Sample');

    $own = SgipDistribution::forceCreate([
        'user_id' => $rep->id,
        'account_master_id' => $ownDoctor->id,
        'territory_id' => $territory->id,
        'visit_date' => today(),
        'approval_status' => 'submitted',
        'created_by' => $rep->id,
        'updated_by' => $rep->id,
    ]);

    $hidden = SgipDistribution::forceCreate([
        'user_id' => $other->id,
        'account_master_id' => $otherDoctor->id,
        'territory_id' => $territory->id,
        'visit_date' => today(),
        'approval_status' => 'submitted',
        'created_by' => $other->id,
        'updated_by' => $other->id,
    ]);

    $draft = SgipDistribution::forceCreate([
        'user_id' => $rep->id,
        'account_master_id' => $ownDoctor->id,
        'territory_id' => $territory->id,
        'visit_date' => today(),
        'approval_status' => 'draft',
        'created_by' => $rep->id,
        'updated_by' => $rep->id,
    ]);

    SgipDistributionItem::forceCreate([
        'sgip_distribution_id' => $own->id,
        'item_master_id' => $item->id,
        'quantity' => 2,
        'unit_value' => 8,
        'created_by' => $rep->id,
        'updated_by' => $rep->id,
    ]);

    SgipDistributionItem::forceCreate([
        'sgip_distribution_id' => $hidden->id,
        'item_master_id' => $item->id,
        'quantity' => 6,
        'unit_value' => 8,
        'created_by' => $other->id,
        'updated_by' => $other->id,
    ]);

    SgipDistributionItem::forceCreate([
        'sgip_distribution_id' => $draft->id,
        'item_master_id' => $item->id,
        'quantity' => 1,
        'unit_value' => 8,
        'created_by' => $rep->id,
        'updated_by' => $rep->id,
    ]);

    expect(app(DoctorSampleLedgerQuery::class)->builder()->count())->toBe(1);
});

it('scopes SGIP violations to visible distributions', function () {
    $territory = reportingTerritory('Violation North');
    $rep = reportingUser('ViolationRep');
    $other = reportingUser('ViolationOther');

    $this->actingAs($rep);

    $ownDoctor = reportingDoctor($rep, 'Dr Own Violation');
    $otherDoctor = reportingDoctor($other, 'Dr Other Violation');

    $own = SgipDistribution::forceCreate([
        'user_id' => $rep->id,
        'account_master_id' => $ownDoctor->id,
        'territory_id' => $territory->id,
        'visit_date' => today(),
        'approval_status' => 'approved',
        'created_by' => $rep->id,
        'updated_by' => $rep->id,
    ]);

    $hidden = SgipDistribution::forceCreate([
        'user_id' => $other->id,
        'account_master_id' => $otherDoctor->id,
        'territory_id' => $territory->id,
        'visit_date' => today(),
        'approval_status' => 'approved',
        'created_by' => $other->id,
        'updated_by' => $other->id,
    ]);

    SgipViolation::forceCreate([
        'sgip_distribution_id' => $own->id,
        'violation_type' => 'quantity',
        'allowed_value' => 1,
        'actual_value' => 4,
        'created_by' => $rep->id,
        'updated_by' => $rep->id,
    ]);

    SgipViolation::forceCreate([
        'sgip_distribution_id' => $hidden->id,
        'violation_type' => 'value',
        'allowed_value' => 10,
        'actual_value' => 50,
        'created_by' => $other->id,
        'updated_by' => $other->id,
    ]);

    expect(app(SgipComplianceQuery::class)->builder()->count())->toBe(1);
});
