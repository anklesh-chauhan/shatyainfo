<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\AverageCallsChart;
use App\Filament\Widgets\CallsByDayChart;
use App\Filament\Widgets\CallsByPersonChart;
use App\Filament\Widgets\ExpenseByTypeChart;
use App\Models\ExpenseType;
use App\Models\SalesDcr;
use App\Models\SalesDcrExpense;
use App\Models\SalesTourPlan;
use App\Models\SalesTourPlanDetail;
use App\Models\Visit;
use App\Services\Reports\FieldVisualReport;
use App\Support\DashboardLayout;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('falls back to the last seven days for an unknown period', function () {
    $period = app(FieldVisualReport::class)->period('not-a-period');

    expect($period['key'])->toBe('last_7')
        ->and($period['from']->toDateString())->toBe(today()->subDays(6)->toDateString())
        ->and($period['until']->toDateString())->toBe(today()->toDateString());
});

it('counts only visible field work inside the selected period', function () {
    $territory = reportingTerritory('Visual North');
    $patch = reportingPatch($territory, 'Visual Patch');
    $rep = reportingUser('VisualRep');
    $other = reportingUser('HiddenRep');

    $this->actingAs($rep);

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

    Visit::withoutEvents(function () use ($territory, $patch, $rep, $other): void {
        foreach ([
            ['rep' => $rep, 'status' => 'completed', 'date' => today(), 'number' => 'VIS-DONE'],
            ['rep' => $rep, 'status' => 'started', 'date' => today(), 'number' => 'VIS-OPEN'],
            ['rep' => $rep, 'status' => 'cancelled', 'date' => today(), 'number' => 'VIS-STOP'],
            ['rep' => $rep, 'status' => 'completed', 'date' => today()->subDays(40), 'number' => 'VIS-OLD'],
            ['rep' => $other, 'status' => 'completed', 'date' => today(), 'number' => 'VIS-HIDDEN'],
        ] as $visit) {
            Visit::forceCreate([
                'document_number' => $visit['number'].'-'.fake()->unique()->numerify('####'),
                'employee_id' => $visit['rep']->id,
                'territory_id' => $territory->id,
                'patch_id' => $patch->id,
                'visit_date' => $visit['date'],
                'visit_status' => $visit['status'],
                'created_by' => $visit['rep']->id,
                'updated_by' => $visit['rep']->id,
            ]);
        }
    });

    $ownDcr = SalesDcr::forceCreate([
        'dcr_date' => today(),
        'user_id' => $rep->id,
        'approval_status' => 'draft',
        'territory_id' => $territory->id,
        'created_by' => $rep->id,
        'updated_by' => $rep->id,
    ]);

    SalesDcr::forceCreate([
        'dcr_date' => today(),
        'user_id' => $rep->id,
        'approval_status' => 'approved',
        'territory_id' => $territory->id,
        'created_by' => $rep->id,
        'updated_by' => $rep->id,
    ]);

    $hiddenDcr = SalesDcr::forceCreate([
        'dcr_date' => today(),
        'user_id' => $other->id,
        'approval_status' => 'pending',
        'territory_id' => $territory->id,
        'created_by' => $other->id,
        'updated_by' => $other->id,
    ]);

    $type = ExpenseType::query()->create([
        'name' => 'Daily allowance '.fake()->unique()->numerify('###'),
        'code' => fake()->unique()->bothify('DA###'),
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
        'sales_dcr_id' => $hiddenDcr->id,
        'expense_type_id' => $type->id,
        'amount' => 250,
        'is_auto_calculated' => false,
        'created_by' => $other->id,
        'updated_by' => $other->id,
    ]);

    $report = app(FieldVisualReport::class);

    $calls = $report->callsByDay($rep, 'today');
    expect($calls['labels'])->toHaveCount(1)
        ->and($calls['completed'])->toBe([1])
        ->and($calls['open'])->toBe([1])
        ->and($calls['cancelled'])->toBe([1]);

    expect($report->planAndCalls($rep, 'today')['data'])->toBe([1, 1, 1]);

    $statuses = $report->callStatus($rep, 'today');
    expect(array_combine($statuses['labels'], $statuses['data']))->toMatchArray([
        'Completed' => 1,
        'In progress' => 1,
        'Cancelled' => 1,
    ]);

    $dcrs = $report->dcrStatus($rep, 'today');
    expect(array_combine($dcrs['labels'], $dcrs['data']))->toMatchArray([
        'Draft' => 1,
        'Approved' => 1,
    ]);

    $expenses = $report->expensesByType($rep, 'today');
    expect($expenses['labels'])->toBe([$type->name])
        ->and($expenses['data'])->toBe([100.0])
        ->and($expenses['total'])->toBe(100.0);

    $people = $report->callsByEmployee($rep, 'today');
    expect($people['labels'])->toBe(['VisualRep'])
        ->and($people['data'])->toBe([1]);

    $averages = $report->averageCalls($rep, 'today');
    expect($averages['labels'])->toBe(['VisualRep'])
        ->and($averages['data'])->toBe([1.0])
        ->and($averages['team_average'])->toBe(1.0)
        ->and($averages['days'])->toBe(1);

    $weekAverages = $report->averageCalls($rep, 'last_7');
    expect($weekAverages['labels'])->toBe(['VisualRep'])
        ->and($weekAverages['data'])->toBe([0.1])
        ->and($weekAverages['team_average'])->toBe(0.1)
        ->and($weekAverages['days'])->toBe(7);

    expect($report->callsByDay($rep, 'last_7')['completed'])->toBe([0, 0, 0, 0, 0, 0, 1])
        ->and(array_sum($report->callsByDay($rep, 'last_30')['completed']))->toBe(1);
});

it('saves a personal chart selection and hides the rest', function () {
    $rep = reportingUser('LayoutRep');

    $this->actingAs($rep);

    expect(app(DashboardLayout::class)->chartWidgets($rep))->toHaveCount(7)
        ->and(app(DashboardLayout::class)->sanitize(['calls_by_day', 'not-a-chart']))->toBe(['calls_by_day']);

    livewire(Dashboard::class)
        ->assertSuccessful()
        ->assertSee('Last 7 days')
        ->assertSee('Calls by day')
        ->callAction('periodlast_30')
        ->assertSet('filters.period', 'last_30')
        ->assertSee('Today')
        ->assertSee('This month')
        ->mountAction('customizeReport')
        ->set('mountedActions.0.data.widgets', ['calls_by_day'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($rep->fresh()->dashboard_widgets)->toBe(['calls_by_day']);

    livewire(Dashboard::class)
        ->assertSee('Calls by day');

    expect((new Dashboard)->getWidgets())->toContain(CallsByDayChart::class)
        ->not->toContain(ExpenseByTypeChart::class)
        ->not->toContain(CallsByPersonChart::class);

    livewire(Dashboard::class)
        ->callAction('restoreReport')
        ->assertHasNoActionErrors();

    expect($rep->fresh()->dashboard_widgets)->toBeNull()
        ->and(app(DashboardLayout::class)->selected($rep->fresh()))->toHaveCount(7);
});

it('renders a chart from the saved period', function () {
    $rep = reportingUser('ChartRep');

    $this->actingAs($rep);

    livewire(CallsByDayChart::class)
        ->assertSuccessful()
        ->assertSee('Calls by day')
        ->assertSee('Last 7 days');

    livewire(ExpenseByTypeChart::class)
        ->assertSuccessful()
        ->assertSee('₹0 claimed');

    livewire(AverageCallsChart::class)
        ->assertSuccessful()
        ->assertSee('Average calls')
        ->assertSee('0.0 completed / day');
});
