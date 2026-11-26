<?php

use App\Filament\Pages\PunchIn;
use App\Filament\Resources\DailyAttendances\DailyAttendanceResource;
use App\Filament\Resources\DailyAttendances\Pages\ListDailyAttendances;
use App\Filament\Resources\DailyAttendances\Pages\ViewDailyAttendances;
use App\Models\AttendancePunch;
use App\Models\DailyAttendance;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('shows the punch in action for an employee with no attendance today', function () {
    $user = reportingUser('PunchRep');

    grantPagePermissions($user, 'View:PunchIn');
    $this->actingAs($user);

    livewire(PunchIn::class)
        ->assertSuccessful()
        ->assertActionVisible('punchIn')
        ->assertActionHidden('punchOut');
});

it('records the admin punch in and out and calculates attendance without a current tenant', function () {
    $this->travelTo(today()->setTime(9, 0));
    $user = grantPagePermissions(reportingUser('Admin Punch'), 'View:PunchIn',
        'ViewAny:DailyAttendance', 'View:DailyAttendance', 'Update:DailyAttendance', 'ViewOwn:DailyAttendance');
    reportingAssignShift($user, reportingShift('Admin Day Shift'));
    $this->actingAs($user);
    $coordinates = ['latitude' => 19.0760, 'longitude' => 72.8777];

    livewire(PunchIn::class)
        ->call('doPunchIn', $coordinates)
        ->assertNotified('Punch In Successfully!')
        ->assertActionVisible('punchOut')
        ->assertActionHidden('punchIn')
        ->assertSee('🚪 Punch Out');

    $attendance = DailyAttendance::query()->sole();
    expect($attendance->employee_id)->toBe($user->employee->id)
        ->and($attendance->first_punch_in->format('H:i'))->toBe('09:00')
        ->and($attendance->status->status_code)->toBe('SP');

    $this->travelTo(today()->setTime(18, 0));

    livewire(PunchIn::class)
        ->call('doPunchOut', $coordinates)
        ->assertNotified('Checked Out Successfully!')
        ->assertActionVisible('completed')
        ->assertActionHidden('punchOut')
        ->assertSee('✔ Attendance Completed')
        ->assertActionDisabled('completed');

    expect($attendance->fresh()->last_punch_out->format('H:i'))->toBe('18:00')
        ->and($attendance->fresh()->actual_working_minutes)->toBe(540)
        ->and($attendance->fresh()->status->status_code)->toBe('DP')
        ->and(AttendancePunch::query()->count())->toBe(2);

    livewire(ListDailyAttendances::class)
        ->assertSuccessful()
        ->searchTable('Admin Punch')
        ->assertCanSeeTableRecords([$attendance])
        ->sortTable('employee.full_name')
        ->assertCanSeeTableRecords([$attendance])
        ->callAction(TestAction::make('recalculate')->table($attendance))
        ->assertNotified('Attendance recalculated successfully');

    livewire(ViewDailyAttendances::class, ['record' => $attendance->getKey()])
        ->assertSuccessful();
});

it('rejects duplicate admin punches without adding records', function () {
    $user = grantPagePermissions(reportingUser('Duplicate Punch'), 'View:PunchIn');
    reportingAssignShift($user, reportingShift('Duplicate Shift'));
    $this->actingAs($user);
    $coordinates = ['latitude' => 19.0760, 'longitude' => 72.8777];

    livewire(PunchIn::class)->call('doPunchIn', $coordinates);
    livewire(PunchIn::class)->call('doPunchIn', $coordinates)
        ->assertNotified('Unable to record attendance');
    livewire(PunchIn::class)->call('doPunchOut', $coordinates);
    livewire(PunchIn::class)->call('doPunchOut', $coordinates)
        ->assertNotified('Unable to record attendance');

    expect(DailyAttendance::query()->count())->toBe(1)
        ->and(AttendancePunch::query()->where('punch_type', 'in')->count())->toBe(1)
        ->and(AttendancePunch::query()->where('punch_type', 'out')->count())->toBe(1);
});

it('requires valid coordinates for admin punches', function (array $coordinates) {
    $user = grantPagePermissions(reportingUser('GPS Punch'), 'View:PunchIn');
    reportingAssignShift($user, reportingShift('GPS Shift'));
    $this->actingAs($user);

    livewire(PunchIn::class)->call('doPunchIn', $coordinates)
        ->assertNotified('Unable to record attendance');

    expect(DailyAttendance::query()->count())->toBe(0)
        ->and(AttendancePunch::query()->count())->toBe(0);
})->with([
    'missing GPS' => [[]],
    'invalid latitude' => [['latitude' => 91, 'longitude' => 72]],
    'invalid longitude' => [['latitude' => 19, 'longitude' => 'invalid']],
]);

it('requires a shift and a punch in before admin punch out', function () {
    $user = grantPagePermissions(reportingUser('Missing Shift Punch'), 'View:PunchIn');
    $this->actingAs($user);
    $coordinates = ['latitude' => 19.0760, 'longitude' => 72.8777];

    livewire(PunchIn::class)->call('doPunchIn', $coordinates)
        ->assertNotified('Unable to record attendance');
    livewire(PunchIn::class)->call('doPunchOut', $coordinates)
        ->assertNotified('Unable to record attendance');

    expect(DailyAttendance::query()->count())->toBe(0)
        ->and(AttendancePunch::query()->count())->toBe(0);
});

it('denies punch access without permission or an active employee', function () {
    $user = reportingUser('Unauthorized Punch');
    $this->actingAs($user);
    livewire(PunchIn::class)->assertForbidden();

    grantPagePermissions($user, 'View:PunchIn');
    $user->employee->update(['is_active' => false]);
    $this->actingAs($user->fresh());
    livewire(PunchIn::class)->assertForbidden();

    $this->actingAs(grantPagePermissions(User::factory()->create(), 'View:PunchIn'));
    livewire(PunchIn::class)->assertForbidden();
});

it('shows imported attendance to its employee and preserves full admin visibility', function () {
    $employeeUser = grantPagePermissions(reportingUser('Attendance Owner'), 'ViewOwn:DailyAttendance');
    $otherUser = reportingUser('Other Attendance');
    $shift = reportingShift('Visibility Shift');
    $status = reportingAttendanceStatus('Present');
    $records = collect([$employeeUser, $otherUser])->map(fn (User $user): DailyAttendance => DailyAttendance::query()->create([
        'employee_id' => $user->employee->id,
        'shift_master_id' => $shift->id,
        'attendance_date' => today(),
        'status_id' => $status->id,
    ]));

    $this->actingAs($employeeUser);
    expect(DailyAttendanceResource::getEloquentQuery()->pluck('id')->all())->toBe([$records[0]->id]);

    $employeeUser->assignRole(Role::findOrCreate('super_admin', 'web'));
    expect(DailyAttendanceResource::getEloquentQuery()->pluck('id')->all())->toBe($records->pluck('id')->all());
});
