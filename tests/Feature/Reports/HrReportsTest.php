<?php

use App\Filament\Pages\LeaveLedger as LegacyLeaveLedger;
use App\Filament\Pages\Reports\AttendanceRegister;
use App\Filament\Pages\Reports\LeaveLedger;
use App\Filament\Pages\Reports\PunchVsTour;
use App\Filament\Pages\Reports\ReportsOverview;
use App\Models\DailyAttendance;
use App\Models\LeaveAdjustment;
use App\Models\LeaveApplication;
use App\Models\Visit;
use App\Services\Reports\AttendanceRegisterQuery;
use App\Services\Reports\LeaveLedgerQuery;
use App\Services\Reports\PunchVsTourQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

it('lists visible daily attendance rows', function () {
    $rep = reportingUser('AttendVisible');
    $other = reportingUser('AttendHidden');
    $shift = reportingShift('General');
    $status = reportingAttendanceStatus('Present');

    $this->actingAs($rep);

    DailyAttendance::forceCreate([
        'employee_id' => $rep->employee_id,
        'shift_master_id' => $shift->id,
        'attendance_date' => today(),
        'first_punch_in' => '09:15:00',
        'last_punch_out' => '18:10:00',
        'status_id' => $status->id,
        'created_by' => $rep->id,
        'updated_by' => $rep->id,
    ]);

    DailyAttendance::forceCreate([
        'employee_id' => $other->employee_id,
        'shift_master_id' => $shift->id,
        'attendance_date' => today(),
        'first_punch_in' => '11:40:00',
        'last_punch_out' => '19:05:00',
        'status_id' => $status->id,
        'created_by' => $other->id,
        'updated_by' => $other->id,
    ]);

    expect(app(AttendanceRegisterQuery::class)->builder()->count())->toBe(1);

    livewire(AttendanceRegister::class)
        ->assertSuccessful()
        ->assertSee('09:15')
        ->assertSee($status->status)
        ->assertDontSee('11:40');
});

it('flags punch without visit and visit without punch', function () {
    $territory = reportingTerritory('Tour West');
    $patch = reportingPatch($territory, 'Tour Patch');
    $rep = reportingUser('TourRep');
    $other = reportingUser('OtherTour');
    $shift = reportingShift('Field');
    $status = reportingAttendanceStatus('On Duty');

    $this->actingAs($rep);

    DailyAttendance::forceCreate([
        'employee_id' => $rep->employee_id,
        'shift_master_id' => $shift->id,
        'attendance_date' => today(),
        'first_punch_in' => '08:45:00',
        'status_id' => $status->id,
        'created_by' => $rep->id,
        'updated_by' => $rep->id,
    ]);

    $visitWithoutPunch = 'VIS-NOPUNCH-'.fake()->unique()->numerify('####');
    $hiddenVisit = 'VIS-HIDDEN-'.fake()->unique()->numerify('####');
    $matchedVisit = 'VIS-MATCHED-'.fake()->unique()->numerify('####');

    Visit::withoutEvents(function () use ($visitWithoutPunch, $hiddenVisit, $matchedVisit, $rep, $other, $territory, $patch): void {
        Visit::forceCreate([
            'document_number' => $visitWithoutPunch,
            'employee_id' => $rep->id,
            'territory_id' => $territory->id,
            'patch_id' => $patch->id,
            'visit_date' => today()->subDay(),
            'visit_status' => 'completed',
            'created_by' => $rep->id,
            'updated_by' => $rep->id,
        ]);

        Visit::forceCreate([
            'document_number' => $hiddenVisit,
            'employee_id' => $other->id,
            'territory_id' => $territory->id,
            'patch_id' => $patch->id,
            'visit_date' => today(),
            'visit_status' => 'started',
            'created_by' => $other->id,
            'updated_by' => $other->id,
        ]);

        Visit::forceCreate([
            'document_number' => $matchedVisit,
            'employee_id' => $rep->id,
            'territory_id' => $territory->id,
            'patch_id' => $patch->id,
            'visit_date' => today()->subDays(2),
            'visit_status' => 'completed',
            'created_by' => $rep->id,
            'updated_by' => $rep->id,
        ]);
    });

    DailyAttendance::forceCreate([
        'employee_id' => $rep->employee_id,
        'shift_master_id' => $shift->id,
        'attendance_date' => today()->subDays(2),
        'first_punch_in' => '09:00:00',
        'status_id' => $status->id,
        'created_by' => $rep->id,
        'updated_by' => $rep->id,
    ]);

    expect(app(PunchVsTourQuery::class)->builder()->count())->toBe(2);

    livewire(PunchVsTour::class)
        ->assertSuccessful()
        ->assertSee('Punch without visit')
        ->assertSee($visitWithoutPunch)
        ->assertDontSee($hiddenVisit)
        ->assertDontSee($matchedVisit);
});

it('lists visible leave ledger movements', function () {
    $rep = reportingUser('LeaveVisible');
    $other = reportingUser('LeaveHidden');
    $type = reportingLeaveType('Casual');

    $this->actingAs($rep);

    LeaveAdjustment::forceCreate([
        'employee_id' => $rep->employee_id,
        'leave_type_id' => $type->id,
        'type' => 'positive',
        'days' => 2,
        'reason' => 'Visible leave credit',
        'effective_date' => today(),
        'created_by' => $rep->id,
        'updated_by' => $rep->id,
    ]);

    LeaveAdjustment::forceCreate([
        'employee_id' => $other->employee_id,
        'leave_type_id' => $type->id,
        'type' => 'positive',
        'days' => 5,
        'reason' => 'Hidden leave credit',
        'effective_date' => today(),
        'created_by' => $other->id,
        'updated_by' => $other->id,
    ]);

    expect(app(LeaveLedgerQuery::class)->builder()->count())->toBe(1);

    livewire(LeaveLedger::class)
        ->assertSuccessful()
        ->assertSee('Visible leave credit')
        ->assertSee($type->name)
        ->assertDontSee('Hidden leave credit');
});

it('exposes phase 4 reports on the overview catalog', function () {
    $user = reportingUser('HrCatalog');

    $this->actingAs($user);

    livewire(ReportsOverview::class)
        ->assertSuccessful()
        ->assertSee('Attendance register')
        ->assertSee('Punch vs tour')
        ->assertSee('Leave ledger');
});

it('hides the legacy HR leave ledger from navigation', function () {
    expect(LegacyLeaveLedger::shouldRegisterNavigation())->toBeFalse();
});

it('excludes unapproved and cancelled leave from the ledger', function () {
    $user = reportingUser('Approved Ledger');
    $this->actingAs($user);
    $type = reportingLeaveType('Ledger Status');
    $leave = LeaveApplication::create(['employee_id' => $user->employee->id, 'leave_type_id' => $type->id, 'from_date' => today(), 'to_date' => today()->addDays(2)]);
    foreach (['applied', 'approved', 'cancelled'] as $offset => $status) {
        $leave->instances()->create(['employee_id' => $user->employee->id, 'leave_type_id' => $type->id, 'date' => today()->addDays($offset), 'pay_factor' => 1, 'approval_status' => $status]);
    }
    expect(app(LeaveLedgerQuery::class)->builder()->count())->toBe(1)
        ->and((float) app(LeaveLedgerQuery::class)->builder()->sum('amount'))->toBe(-1.0);
});
