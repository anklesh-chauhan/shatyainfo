<?php

use App\Filament\Resources\TerritorySalesAssignments\Pages\ManageTerritorySalesAssignments;
use App\Filament\Resources\TerritorySalesTargets\Pages\ManageTerritorySalesTargets;
use App\Models\AccountReportingMaster;
use App\Models\ContactDetail;
use App\Models\DailyAttendance;
use App\Models\RcpaCompetitor;
use App\Models\SalesInvoice;
use App\Models\SecondarySalesStatement;
use App\Models\TerritorySalesAssignment;
use App\Services\Field\FieldRcpaService;
use App\Services\Field\SalesPerformanceService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->month = CarbonImmutable::now()->startOfMonth()->startOfDay();
    $this->travelTo($this->month->addDays(3)->setTime(12, 0));
    $this->manager = reportingUser('PerformanceManager');
    foreach (['Manage:SalesTargets', 'View:SalesTargets', 'AccessAllRecords'] as $name) {
        Permission::findOrCreate($name, 'web');
    }
    $this->manager->givePermissionTo(['Manage:SalesTargets', 'View:SalesTargets', 'AccessAllRecords']);
    $this->old = reportingUser('PreviousMR');
    $this->new = reportingUser('ReplacementMR');
    Sanctum::actingAs($this->manager);
    $this->territory = reportingTerritory('Performance Territory');
    $this->stockist = reportingDoctor($this->old, 'Performance Stockist');
    $this->stockist->update(['territory_id' => $this->territory->id]);
    $this->service = app(SalesPerformanceService::class);
    $this->target = $this->service->createTarget($this->manager, ['territory_id' => $this->territory->id,
        'period_type' => 'monthly', 'starts_on' => $this->month->toDateString(), 'target_value_lakhs' => '1', 'target_quantity' => 300, 'note' => 'Agreed monthly plan']);
    $item = reportingSampleItem('PERF-SKU', 'Performance SKU');
    $this->item = $item;
    $statement = SecondarySalesStatement::query()->create(['stockist_id' => $this->stockist->id, 'territory_id' => $this->territory->id,
        'created_by' => $this->old->id, 'reviewer_id' => $this->manager->id, 'period' => $this->month->format('Y-m'),
        'source_path' => 'isolated-performance-fixture.csv', 'source_sha256' => hash('sha256', 'performance-fixture'), 'status' => 'pending_review']);
    foreach ([['sale', 30, 10002, 1, false], ['return', 3, 100, 0, true]] as [$type, $quantity, $gross, $discount, $verified]) {
        $statement->lines()->create(['row_number' => $type === 'sale' ? 2 : 3, 'invoice_number' => $type, 'invoice_date' => $this->month->addDays(5),
            'batch_no' => 'B1', 'item_master_id' => $item->id, 'unit' => 'unit', 'quantity' => $quantity, 'conversion_factor' => 1,
            'base_quantity' => $quantity, 'gross_paise' => $gross, 'discount_paise' => $discount, 'transaction_type' => $type,
            'physically_verified' => $verified, 'raw' => [], 'errors' => []]);
    }
    $statement->update(['status' => 'approved', 'reviewed_at' => now()]);
});

it('uses only net secondary sales and splits transfers on the tenth by active attendance days with exact paise conservation', function () {
    $this->service->assign($this->manager, ['territory_id' => $this->territory->id, 'user_id' => $this->old->id, 'effective_from' => $this->month->toDateString(), 'reason' => 'Initial owner']);
    $this->service->assign($this->manager, ['territory_id' => $this->territory->id, 'user_id' => $this->new->id, 'effective_from' => $this->month->addDays(9)->toDateString(), 'reason' => 'Transfer on tenth']);
    $contact = ContactDetail::forceCreate(['first_name' => 'Primary Contact']);
    $invoice = SalesInvoice::forceCreate(['document_number' => 'PERFORMANCE-PRIMARY', 'account_master_id' => $this->stockist->id,
        'contact_detail_id' => $contact->id, 'date' => $this->month->addDays(14), 'status' => 'draft', 'approval_status' => 'draft', 'total' => 10000, 'currency' => 'INR']);
    expect((int) $invoice->territory_id)->toBe($this->territory->id);
    $invoice->updateQuietly(['status' => 'sent', 'approval_status' => 'approved']);
    $this->travelTo($this->month->endOfMonth()->setTime(18, 0));
    $missing = $this->service->dashboard($this->manager, $this->target);
    expect($missing['unallocated_paise'])->toBe(9901)->and($missing['months'][0]['allocation']['state'])->toBe('missing_active_days');
    $shift = reportingShift('Performance Shift');
    $status = reportingAttendanceStatus('Performance Present');
    foreach ([[$this->old, 2], [$this->new, 11], [$this->new, 12]] as [$owner, $day]) {
        DailyAttendance::withoutEvents(fn () => DailyAttendance::query()->create(['employee_id' => $owner->employee->id, 'shift_master_id' => $shift->id,
            'attendance_date' => $this->month->day($day), 'status_id' => $status->id, 'first_punch_in' => '09:00:00', 'last_punch_out' => '18:00:00', 'is_absent' => false]));
    }
    foreach (['View:Rcpa', 'Capture:Rcpa', 'View:AccountMaster'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $this->manager->givePermissionTo(['View:Rcpa', 'Capture:Rcpa', 'View:AccountMaster']);
    $this->stockist->update(['rcpa_tier' => 'A']);
    $segment = AccountReportingMaster::query()->create(['kind' => 'therapy_area', 'code' => 'performance_segment', 'name' => 'Test segment', 'is_active' => true]);
    $brands = [];
    for ($rank = 1; $rank <= 5; $rank++) {
        $brand = RcpaCompetitor::query()->create(['therapy_area_id' => $segment->id, 'rank' => $rank, 'name' => 'Test market brand '.$rank]);
        $brands[] = ['competitor_id' => $brand->id, 'present' => true];
    }
    app(FieldRcpaService::class)->capture($this->manager, ['event_id' => (string) Str::uuid(), 'captured_at' => now()->utc()->toIso8601String(),
        'observed_on' => today()->toDateString(), 'chemist_id' => $this->stockist->id, 'therapy_area_id' => $segment->id, 'item_master_id' => $this->item->id,
        'stock_status' => 'available', 'doctor_rx_share' => 95, 'offtake_quantity' => 999999, 'offtake_days' => 7, 'competitors' => $brands]);
    $result = $this->getJson('/api/v1/sales-targets/'.$this->target->id.'/achievement')->assertSuccessful()
        ->assertJsonPath('data.ledger.net_paise', 9901)->assertJsonPath('data.primary_sales_paise', 1000000)
        ->assertJsonPath('data.value_achievement_percent', 0.1)->assertJsonPath('data.rcpa_comparison.0.data_flag', 'ESTIMATED_FIELD')->json('data');
    expect(array_sum(array_column($result['breakdowns']['stockists'], 'net_paise')))->toBe(9901)
        ->and(array_sum(array_column($result['breakdowns']['products'], 'net_paise')))->toBe(9901)->and($result['trends'])->toHaveCount(6);
    $credits = collect($result['credits'])->keyBy('user_id');
    expect($credits[$this->old->id]['value_paise'])->toBe(3300)->and($credits[$this->new->id]['value_paise'])->toBe(6601)
        ->and(array_sum(array_column($result['credits'], 'value_paise')))->toBe(9901)
        ->and((float) array_sum(array_column($result['credits'], 'base_quantity')))->toBe(27.0)
        ->and($result['incentive_basis'])->toBe('100_percent_secondary_sales');
    $other = reportingTerritory('Different Current Territory');
    $this->stockist->update(['territory_id' => $other->id]);
    expect((int) $invoice->fresh()->territory_id)->toBe($this->territory->id)
        ->and($this->service->dashboard($this->manager, $this->target)['ledger']['net_paise'])->toBe(9901);
});

it('transfers the complete month before the tenth and keeps incomplete history visibly unallocated', function () {
    $before = $this->service->dashboard($this->manager, $this->target);
    expect($before['months'][0]['allocation']['state'])->toBe('missing_ownership_history');
    $this->service->assign($this->manager, ['territory_id' => $this->territory->id, 'user_id' => $this->old->id, 'effective_from' => $this->month->toDateString(), 'reason' => 'Initial history']);
    $this->service->assign($this->manager, ['territory_id' => $this->territory->id, 'user_id' => $this->new->id, 'effective_from' => $this->month->addDays(8)->toDateString(), 'reason' => 'Transfer before tenth']);
    $this->travelTo($this->month->endOfMonth()->setTime(18, 0));
    $result = $this->service->dashboard($this->manager, $this->target);
    expect($result['months'][0]['allocation']['state'])->toBe('early_transfer')->and($result['credits'])->toHaveCount(1)
        ->and($result['credits'][0]['user_id'])->toBe($this->new->id)->and($result['credits'][0]['value_paise'])->toBe(9901);
    expect(fn () => TerritorySalesAssignment::query()->oldest('id')->firstOrFail()->update(['user_id' => $this->new->id]))->toThrow(HttpException::class);
});

it('configures explicit calendar or fiscal periods, protects started targets and records future revisions', function () {
    $quarter = $this->service->createTarget($this->manager, ['territory_id' => $this->territory->id, 'period_type' => 'quarterly',
        'starts_on' => $this->month->toDateString(), 'target_value_lakhs' => '3.5', 'target_quantity' => 900, 'note' => 'Three explicit months']);
    expect($quarter->ends_on->toDateString())->toBe($this->month->addMonths(3)->subDay()->toDateString());
    $future = $this->service->createTarget($this->manager, ['territory_id' => $this->territory->id, 'period_type' => 'annual',
        'starts_on' => $this->month->addMonth()->toDateString(), 'target_value_lakhs' => '10', 'target_quantity' => 5000, 'note' => 'Explicit year start']);
    $this->service->reviseTarget($this->manager, $future, ['target_value_lakhs' => '12', 'target_quantity' => 5500, 'note' => 'Approved future revision']);
    expect($future->fresh()->target_value_paise)->toBe(120000000)->and($future->fresh()->revisions)->toHaveCount(1);
    expect(fn () => $this->service->reviseTarget($this->manager, $this->target, ['target_value_lakhs' => '2', 'target_quantity' => 500, 'note' => 'Late change']))->toThrow(HttpException::class);
    $this->postJson('/api/v1/sales-targets', ['territory_id' => $this->territory->id, 'period_type' => 'monthly', 'starts_on' => $this->month->toDateString(),
        'target_value_lakhs' => '1', 'target_quantity' => 300, 'note' => 'Duplicate target'])->assertConflict();
    $viewer = reportingUser('UnassignedTargetViewer');
    $viewer->givePermissionTo('View:SalesTargets');
    Sanctum::actingAs($viewer);
    $this->getJson('/api/v1/sales-targets/'.$this->target->id.'/achievement')->assertNotFound();
});

it('renders target planning, assignment history and the achievement dashboard', function () {
    $this->manager->assignRole(Role::findOrCreate('super_admin', 'web'));
    $this->actingAs($this->manager);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(ManageTerritorySalesTargets::class)->assertSuccessful();
    Livewire::test(ManageTerritorySalesAssignments::class)->assertSuccessful();
    $data = $this->service->dashboard($this->manager, $this->target);
    expect(view('filament.sfa.target-performance', compact('data'))->render())->toContain('Net achievement', 'Quantity achievement', 'Primary invoiced value');
});
