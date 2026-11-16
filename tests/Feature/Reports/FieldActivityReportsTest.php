<?php

use App\Filament\Pages\Reports\DcrRegister;
use App\Filament\Pages\Reports\ExpenseSummary;
use App\Filament\Pages\Reports\VisitCoverage;
use App\Models\ExpenseType;
use App\Models\SalesDcr;
use App\Models\SalesDcrExpense;
use App\Models\SalesTourPlan;
use App\Models\SalesTourPlanDetail;
use App\Models\Territory;
use App\Services\Reports\ExpenseSummaryQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

it('lists visible DCRs on the register', function () {
    $territory = Territory::query()->create([
        'name' => 'West',
        'code' => fake()->unique()->bothify('T###'),
    ]);
    $rep = reportingUser('DcrRep');

    $this->actingAs(grantPagePermissions($rep, 'View:DcrRegister'));

    SalesDcr::forceCreate([
        'dcr_date' => today(),
        'user_id' => $rep->id,
        'approval_status' => 'draft',
        'territory_id' => $territory->id,
        'visits_count' => 3,
        'distance_covered' => 12.5,
        'total_expense' => 150,
        'created_by' => $rep->id,
        'updated_by' => $rep->id,
    ]);

    livewire(DcrRegister::class)
        ->assertSuccessful()
        ->assertSee('DcrRep')
        ->assertSee('West');
});

it('lists tour plan days on visit coverage', function () {
    $territory = Territory::query()->create([
        'name' => 'East',
        'code' => fake()->unique()->bothify('T###'),
    ]);
    $rep = reportingUser('CoverageRep');

    $this->actingAs(grantPagePermissions($rep, 'View:VisitCoverage'));

    $plan = SalesTourPlan::forceCreate([
        'user_id' => $rep->id,
        'month' => now()->format('Y-m'),
        'approval_status' => 'approved',
        'created_by' => $rep->id,
        'updated_by' => $rep->id,
    ]);

    SalesTourPlanDetail::forceCreate([
        'sales_tour_plan_id' => $plan->id,
        'date' => today(),
        'territory_id' => $territory->id,
        'created_by' => $rep->id,
        'updated_by' => $rep->id,
    ]);

    livewire(VisitCoverage::class)
        ->assertSuccessful()
        ->assertSee('CoverageRep')
        ->assertSee('East');
});

it('hides another representative’s expenses without full access', function () {
    $north = Territory::query()->create([
        'name' => 'North Exp',
        'code' => fake()->unique()->bothify('T###'),
    ]);
    $south = Territory::query()->create([
        'name' => 'South Exp',
        'code' => fake()->unique()->bothify('T###'),
    ]);

    $rep = reportingUser('ExpRep');
    $other = reportingUser('OtherExp');

    $this->actingAs(grantPagePermissions($rep, 'View:ExpenseSummary'));

    $ownDcr = SalesDcr::forceCreate([
        'dcr_date' => today(),
        'user_id' => $rep->id,
        'approval_status' => 'draft',
        'territory_id' => $north->id,
        'created_by' => $rep->id,
        'updated_by' => $rep->id,
    ]);

    $otherDcr = SalesDcr::forceCreate([
        'dcr_date' => today(),
        'user_id' => $other->id,
        'approval_status' => 'pending',
        'territory_id' => $south->id,
        'created_by' => $other->id,
        'updated_by' => $other->id,
    ]);

    $type = ExpenseType::query()->create([
        'name' => 'Travel '.fake()->unique()->numerify('###'),
        'code' => fake()->unique()->bothify('TRV###'),
        'is_active' => true,
        'created_by' => $rep->id,
        'updated_by' => $rep->id,
    ]);

    SalesDcrExpense::forceCreate([
        'sales_dcr_id' => $ownDcr->id,
        'expense_type_id' => $type->id,
        'amount' => 100,
        'is_auto_calculated' => false,
        'created_by' => $rep->id,
        'updated_by' => $rep->id,
    ]);

    SalesDcrExpense::forceCreate([
        'sales_dcr_id' => $otherDcr->id,
        'expense_type_id' => $type->id,
        'amount' => 250,
        'is_auto_calculated' => false,
        'created_by' => $other->id,
        'updated_by' => $other->id,
    ]);

    expect(app(ExpenseSummaryQuery::class)->builder()->count())->toBe(1);

    $page = livewire(ExpenseSummary::class)
        ->assertSuccessful()
        ->assertSee($type->name)
        ->assertDontSee('250.00');
    $options = $page->instance()->getTable()->getFilter('employee')->getOptions();
    expect($options)->toHaveKey($rep->id)->not->toHaveKey($other->id);
});
