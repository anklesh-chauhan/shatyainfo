<?php

use App\Filament\Pages\FieldCoverage;
use App\Filament\Pages\FieldTeamOnboarding;
use App\Models\AccountMaster;
use App\Models\FieldUserHq;
use App\Models\SalesDcr;
use App\Models\Visit;
use App\Services\Field\FieldCoverageService;
use App\Services\Field\FieldTeamOnboardingService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-06-01 09:00:00'));
    $this->admin = reportingUser('TeamAdmin');
    $this->rep = reportingUser('TeamRep');
    $this->other = reportingUser('TeamOther');
    $this->admin->assignRole(Role::findOrCreate('super_admin', 'web'));
    foreach (['Manage:FieldTeam', 'ViewAny:AccountMaster', 'Update:AccountMaster', 'ViewOwn:AccountMaster'] as $name) {
        Permission::findOrCreate($name, 'web');
    }
    $this->admin->givePermissionTo(['Manage:FieldTeam', 'ViewAny:AccountMaster', 'Update:AccountMaster']);
    $this->rep->givePermissionTo('ViewAny:AccountMaster');
    $this->actingAs($this->admin);
    $this->hq = reportingLocation('Ahmedabad HQ');
    $this->doctor = reportingDoctor($this->admin, 'Doctor One');
    $this->onboarding = app(FieldTeamOnboardingService::class);
    $this->setup = ['user_id' => $this->rep->id, 'location_master_id' => $this->hq->id,
        'account_ids' => [$this->doctor->id], 'calls_per_month' => 2];
});
function completedCoverageVisit($test, $user, $doctor, string $date, string $approval = 'approved', bool $joint = false): Visit
{
    $dcr = SalesDcr::query()->create(['user_id' => $user->id, 'dcr_date' => $date, 'approval_status' => $approval]);
    $visit = Visit::withoutEvents(fn () => Visit::query()->create([
        'document_number' => 'COV-'.Str::uuid(), 'employee_id' => $user->id,
        'sales_dcr_id' => $dcr->id, 'visit_date' => $date, 'start_time' => $date.' 10:00:00',
        'visit_type' => 'unplanned', 'visit_status' => 'completed', 'is_joint_work' => $joint,
    ]));
    $visit->visitables()->create(['visitable_type' => AccountMaster::class, 'visitable_id' => $doctor->id]);

    return $visit;
}
it('saves HQ assignments and initial targets without a territory and retains transfer history', function () {
    $this->onboarding->save($this->admin, $this->setup);
    expect(FieldUserHq::query()->sole()->location_master_id)->toBe($this->hq->id)
        ->and($this->doctor->fresh()->owner_id)->toBe($this->rep->id)
        ->and($this->doctor->fresh()->territory_id)->toBeNull()
        ->and($this->doctor->callTargets()->sole()->calls_per_month)->toBe(2);
    $this->travelTo(now()->addDay());
    $this->onboarding->save($this->admin, [...$this->setup, 'user_id' => $this->other->id, 'calls_per_month' => 9]);
    expect($this->doctor->assignmentHistory()->where('owner_id', $this->rep->id)->sole()->effective_to)->not->toBeNull()
        ->and($this->doctor->callTargets()->sole()->calls_per_month)->toBe(2);
});
it('counts repeated calls for frequency once for coverage and separates unapproved calls', function () {
    $this->onboarding->save($this->admin, $this->setup);
    $this->travelTo(now()->endOfMonth());
    completedCoverageVisit($this, $this->rep, $this->doctor, '2026-06-02');
    completedCoverageVisit($this, $this->rep, $this->doctor, '2026-06-03');
    completedCoverageVisit($this, $this->rep, $this->doctor, '2026-06-04', 'draft');
    $report = app(FieldCoverageService::class)->forMonth($this->rep, $this->rep->id, '2026-06');
    expect($report['eligible_customers'])->toBe(1)->and($report['covered_customers'])->toBe(1)
        ->and($report['coverage_percent'])->toBe(100.0)->and($report['frequency_percent'])->toBe(100.0)
        ->and($report['customers'][0]['completed_calls'])->toBe(2)
        ->and($report['customers'][0]['pending_calls'])->toBe(1);
});
it('uses historical ownership and prorates frequency when a customer changes MR', function () {
    $this->onboarding->save($this->admin, $this->setup);
    completedCoverageVisit($this, $this->rep, $this->doctor, '2026-06-10');
    $this->travelTo(Carbon::parse('2026-06-16 00:00:00'));
    $this->onboarding->save($this->admin, [...$this->setup, 'user_id' => $this->other->id]);
    completedCoverageVisit($this, $this->rep, $this->doctor, '2026-06-17');
    $this->travelTo(Carbon::parse('2026-07-01 09:00:00'));
    $old = app(FieldCoverageService::class)->forMonth($this->admin, $this->rep->id, '2026-06');
    $new = app(FieldCoverageService::class)->forMonth($this->admin, $this->other->id, '2026-06');
    expect($old['customers'][0]['assigned_days'])->toBe(15)
        ->and($old['customers'][0]['target_calls'])->toBe(1)
        ->and($old['customers'][0]['completed_calls'])->toBe(1)
        ->and($old['unassigned_completed_calls'])->toBe(1)
        ->and($new['customers'][0]['target_calls'])->toBe(1)
        ->and($new['customers'][0]['status'])->toBe('missed');
});
it('shows N/A without a denominator and does not credit joint participation as owned coverage', function () {
    $this->onboarding->save($this->admin, $this->setup);
    $visit = completedCoverageVisit($this, $this->other, $this->doctor, '2026-06-02', joint: true);
    $visit->jointUsers()->attach($this->rep->id);
    $this->travelTo(now()->addDays(3));
    $report = app(FieldCoverageService::class)->forMonth($this->admin, $this->rep->id, '2026-06');
    expect($report['joint_participations'])->toBe(1)->and($report['covered_customers'])->toBe(0);
    $empty = app(FieldCoverageService::class)->forMonth($this->admin, $this->other->id, '2026-06');
    expect($empty['coverage_percent'])->toBeNull()->and($empty['frequency_percent'])->toBeNull();
});
it('limits representatives to their own coverage and denies onboarding writes', function () {
    expect(fn () => $this->onboarding->save($this->rep, $this->setup))->toThrow(HttpException::class);
    expect(fn () => app(FieldCoverageService::class)->forMonth($this->rep, $this->other->id, '2026-06'))->toThrow(HttpException::class);
    Sanctum::actingAs($this->rep);
    $this->getJson('/api/v1/field-coverage?month=2026-06')->assertSuccessful()->assertJsonPath('data.user_id', $this->rep->id);
    $this->getJson('/api/v1/field-coverage?month=invalid')->assertUnprocessable();
});
it('does not partially save HQ or assignments when customer visibility fails', function () {
    expect(fn () => $this->onboarding->save($this->admin, [...$this->setup, 'account_ids' => [$this->doctor->id, 999999]]))->toThrow(HttpException::class);
    expect(FieldUserHq::query()->count())->toBe(0)->and($this->doctor->fresh()->owner_id)->toBe($this->admin->id);
});
it('renders onboarding and coverage and saves the representative setup through Admin', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(FieldTeamOnboarding::class)->assertSuccessful()->fillForm($this->setup)->call('save')->assertHasNoFormErrors()->assertNotified();
    Livewire::test(FieldCoverage::class)->set('userId', $this->rep->id)->assertSuccessful()->assertSee('Doctor One')->assertSee('Ahmedabad HQ');
});

it('makes assigned customers visible under ViewOwn without exposing other customers', function () {
    $this->onboarding->save($this->admin, $this->setup);
    $this->rep->givePermissionTo('ViewOwn:AccountMaster');
    Sanctum::actingAs($this->rep);
    $this->getJson('/api/v1/accounts')->assertSuccessful()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->doctor->id);
    $this->actingAs($this->admin);
    $this->onboarding->save($this->admin, [...$this->setup, 'user_id' => $this->other->id]);
    Sanctum::actingAs($this->rep);
    $this->getJson('/api/v1/accounts')->assertSuccessful()->assertJsonCount(0, 'data');
});

it('initializes this month without overwriting a scheduled future frequency target', function () {
    $this->doctor->callTargets()->create(['calls_per_month' => 10, 'effective_from' => '2026-07-01']);
    $this->onboarding->save($this->admin, $this->setup);
    $targets = $this->doctor->callTargets()->orderBy('effective_from')->get();
    expect($targets)->toHaveCount(2)
        ->and($targets[0]->calls_per_month)->toBe(2)
        ->and($targets[0]->effective_to->toDateString())->toBe('2026-06-30')
        ->and($targets[1]->calls_per_month)->toBe(10);
});

it('excludes zero and missing targets from both denominators', function () {
    $this->onboarding->save($this->admin, [...$this->setup, 'calls_per_month' => 0]);
    $missing = reportingDoctor($this->admin, 'No target');
    $missing->update(['owner_id' => $this->rep->id]);
    completedCoverageVisit($this, $this->rep, $this->doctor, '2026-06-01');
    $report = app(FieldCoverageService::class)->forMonth($this->rep, $this->rep->id, '2026-06');
    $rows = collect($report['customers'])->keyBy('account_id');
    expect($rows[$this->doctor->id]['status'])->toBe('not_required')
        ->and($rows[$missing->id]['status'])->toBe('not_configured')
        ->and($report['eligible_customers'])->toBe(0)
        ->and($report['coverage_percent'])->toBeNull()
        ->and($report['frequency_percent'])->toBeNull();
});

it('caps frequency per customer so extra calls cannot compensate for missed customers', function () {
    $second = reportingDoctor($this->admin, 'Doctor Two');
    $this->onboarding->save($this->admin, [...$this->setup, 'account_ids' => [$this->doctor->id, $second->id]]);
    $this->travelTo(Carbon::parse('2026-07-01'));
    foreach (['2026-06-02', '2026-06-03', '2026-06-04', '2026-06-05'] as $date) {
        completedCoverageVisit($this, $this->rep, $this->doctor, $date);
    }
    $report = app(FieldCoverageService::class)->forMonth($this->rep, $this->rep->id, '2026-06');
    expect($report['covered_customers'])->toBe(1)->and($report['eligible_customers'])->toBe(2)
        ->and($report['target_calls'])->toBe(4)->and($report['credited_frequency_calls'])->toBe(2)
        ->and($report['coverage_percent'])->toBe(50.0)->and($report['frequency_percent'])->toBe(50.0);
});

it('uses check-in ownership at a midday transfer and counts the shared calendar day once per MR', function () {
    $this->onboarding->save($this->admin, $this->setup);
    $before = completedCoverageVisit($this, $this->rep, $this->doctor, '2026-06-16');
    $this->travelTo(Carbon::parse('2026-06-16 12:00:00'));
    $this->onboarding->save($this->admin, [...$this->setup, 'user_id' => $this->other->id]);
    $after = completedCoverageVisit($this, $this->other, $this->doctor, '2026-06-16');
    Visit::withoutEvents(fn () => $after->update(['start_time' => '2026-06-16 12:00:00']));
    $fallback = completedCoverageVisit($this, $this->rep, $this->doctor, '2026-06-16');
    Visit::withoutEvents(fn () => $fallback->update(['start_time' => null]));
    $this->travelTo(Carbon::parse('2026-07-01'));
    $old = app(FieldCoverageService::class)->forMonth($this->admin, $this->rep->id, '2026-06');
    $new = app(FieldCoverageService::class)->forMonth($this->admin, $this->other->id, '2026-06');
    expect($old['customers'][0]['assigned_days'])->toBe(16)
        ->and($old['customers'][0]['target_calls'])->toBe(2)
        ->and($old['customers'][0]['completed_calls'])->toBe(1)
        ->and($old['unassigned_completed_calls'])->toBe(1)
        ->and($new['customers'][0]['assigned_days'])->toBe(15)
        ->and($new['customers'][0]['completed_calls'])->toBe(1);
});

it('calculates due-to-date using the actual number of days in a leap-year month', function () {
    $this->travelTo(Carbon::parse('2028-02-01 09:00:00'));
    $this->onboarding->save($this->admin, [...$this->setup, 'calls_per_month' => 4]);
    $this->travelTo(Carbon::parse('2028-02-15 09:00:00'));
    $report = app(FieldCoverageService::class)->forMonth($this->rep, $this->rep->id, '2028-02');
    expect($report['customers'][0]['assigned_days'])->toBe(29)
        ->and($report['customers'][0]['target_calls'])->toBe(4)
        ->and($report['customers'][0]['expected_to_date'])->toBe(3)
        ->and($report['customers'][0]['status'])->toBe('due');
});

it('counts only completed visits and keeps nonapproved DCRs pending', function () {
    $this->onboarding->save($this->admin, $this->setup);
    foreach (['draft', 'submitted', 'rejected'] as $approval) {
        completedCoverageVisit($this, $this->rep, $this->doctor, '2026-06-01', $approval);
    }
    $unfinished = completedCoverageVisit($this, $this->rep, $this->doctor, '2026-06-01');
    Visit::withoutEvents(fn () => $unfinished->update(['visit_status' => 'started']));
    $report = app(FieldCoverageService::class)->forMonth($this->rep, $this->rep->id, '2026-06');
    expect($report['customers'][0]['completed_calls'])->toBe(0)
        ->and($report['customers'][0]['pending_calls'])->toBe(3)
        ->and($report['covered_customers'])->toBe(0);
});

it('rejects midmonth and overlapping target periods and selects the requested month target', function () {
    expect(fn () => $this->doctor->callTargets()->create(['calls_per_month' => 4, 'effective_from' => '2026-06-15']))
        ->toThrow(ValidationException::class);
    $this->doctor->callTargets()->create(['calls_per_month' => 9, 'effective_from' => '2026-05-01', 'effective_to' => '2026-05-31']);
    $this->onboarding->save($this->admin, $this->setup);
    expect(fn () => $this->doctor->callTargets()->create(['calls_per_month' => 8, 'effective_from' => '2026-06-01']))
        ->toThrow(ValidationException::class);
    $report = app(FieldCoverageService::class)->forMonth($this->rep, $this->rep->id, '2026-06');
    expect($report['customers'][0]['target_calls'])->toBe(2);
});
