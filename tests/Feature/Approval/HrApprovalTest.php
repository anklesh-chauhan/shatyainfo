<?php

use App\Filament\Resources\HrChangeRequests\Pages\ManageHrChangeRequests;
use App\Filament\Resources\LeaveCancellations\Pages\ManageLeaveCancellations;
use App\Jobs\ProcessDailyAttendanceJob;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\ApprovalSetting;
use App\Models\DailyAttendance;
use App\Models\EmploymentDetail;
use App\Models\EmpSalary;
use App\Models\HrChangeRequest;
use App\Models\JobRole;
use App\Models\LeaveAdjustment;
use App\Models\LeaveApplication;
use App\Models\LeaveBalance;
use App\Models\LeaveCancellation;
use App\Models\LeaveEncashment;
use App\Models\PayrollLeaveSnapshot;
use App\Models\Position;
use App\Orchestrators\LeaveApplicationOrchestrator;
use App\Services\Approval\ApprovalService;
use App\Services\Attendance\AttendanceCalculationService;
use App\Services\Attendance\AttendanceStatusResolver;
use App\Services\Attendance\LeaveBalanceCalculator;
use App\Services\Attendance\LeaveWorkflowService;
use App\Services\Attendance\PunchNormalizationService;
use App\Services\Field\FieldLeaveService;
use App\Services\HR\HrChangeService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function hrFlow(string $class): array
{
    $requester = reportingUser('HR Requester');
    $manager = reportingUser('HR Manager');
    $role = JobRole::create(['name' => 'HR Approver', 'code' => 'HRA', 'level' => 2]);
    $position = Position::create(['name' => 'HR Approval', 'code' => 'HRAP', 'job_role_id' => $role->id]);
    $manager->employee->positions()->attach($position->id);
    ApprovalSetting::instance()->update(['enabled_modules' => [$class]]);
    $flow = ApprovalFlow::create(['module' => class_basename($class), 'active' => true]);
    ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'step_order' => 1, 'job_role_id' => $role->id, 'territory_scope' => 'all']);
    test()->actingAs($requester);

    return [$requester, $manager];
}

function hrLeave($user, string $status = 'draft'): LeaveApplication
{
    $type = reportingLeaveType('HR Leave');
    $leave = LeaveApplication::create(['employee_id' => $user->employee->id, 'leave_type_id' => $type->id, 'from_date' => today(), 'to_date' => today(), 'total_days' => 1, 'approval_status' => $status]);
    $leave->instances()->create(['employee_id' => $user->employee->id, 'leave_type_id' => $type->id, 'date' => today(), 'pay_factor' => 1, 'approval_status' => $status === 'draft' ? 'applied' : $status]);

    return $leave;
}

it('finalizes leave when approval is disabled', function () {
    $user = reportingUser('Auto Leave');
    $this->actingAs($user);
    $leave = hrLeave($user);
    app(LeaveWorkflowService::class)->start($leave, []);
    expect($leave->fresh()->approval_status)->toBe('approved')
        ->and($leave->instances()->first()->approval_status)->toBe('approved')
        ->and($leave->employee->id)->toBe($user->employee->id);
    $this->assertDatabaseCount('approvals', 0);
});

it('withdraws pending leave and closes all approval steps', function () {
    [$user, $manager] = hrFlow(LeaveApplication::class);
    $leave = hrLeave($user);
    app(LeaveWorkflowService::class)->start($leave, []);
    $approval = $leave->approval()->first();
    app(LeaveApplicationOrchestrator::class)->cancel($leave->id, $user->id);
    expect($approval->fresh()->approval_status)->toBe('cancelled')
        ->and($approval->steps()->where('status', 'pending')->exists())->toBeFalse()
        ->and(app(ApprovalService::class)->approve($approval, $manager->id))->toBeFalse()
        ->and($leave->fresh()->approval_status)->toBe('cancelled');
});

it('preserves approved leave until cancellation is approved', function (bool $approve) {
    [$user, $manager] = hrFlow(LeaveCancellation::class);
    $leave = hrLeave($user, 'approved');
    app(LeaveApplicationOrchestrator::class)->cancel($leave->id, $user->id);
    $request = LeaveCancellation::firstOrFail();
    expect($leave->fresh()->approval_status)->toBe('approved');
    if ($approve) {
        app(ApprovalService::class)->approve($request->approval, $manager->id);
    } else {
        app(ApprovalService::class)->reject($request->approval, $manager->id);
    }
    expect($leave->fresh()->approval_status)->toBe($approve ? 'cancelled' : 'approved')
        ->and($leave->instances()->first()->approval_status)->toBe($approve ? 'cancelled' : 'approved');
})->with([true, false]);

it('excludes unapproved HR balance transactions and applies approved signs', function (string $class) {
    [$user, $manager] = hrFlow($class);
    $type = reportingLeaveType('Balance Approval');
    LeaveBalance::create(['employee_id' => $user->employee->id, 'leave_type_id' => $type->id, 'opening_balance' => 10, 'year_start_date' => today()->subYear(), 'year_end_date' => today()->addYear()]);
    $record = $class::create(['employee_id' => $user->employee->id, 'leave_type_id' => $type->id, 'days' => 2, ...($class === LeaveAdjustment::class ? ['type' => 'positive', 'reason' => 'Correction', 'effective_date' => today()] : ['amount' => 100, 'encashed_on' => today(), 'payroll_month' => today()->startOfMonth()])]);
    $balance = fn () => (float) app(LeaveBalanceCalculator::class)->calculate($user->employee->id, $type->id)['closing'];
    expect($balance())->toBe(10.0);
    $approval = $record->startApproval();
    expect($balance())->toBe(10.0);
    expect(fn () => $record->fresh()->update(['days' => 8]))->toThrow(ValidationException::class);
    app(ApprovalService::class)->reject($approval, $manager->id);
    expect($balance())->toBe(10.0);
    $approval = $record->fresh()->startApproval();
    app(ApprovalService::class)->approve($approval, $manager->id);
    expect($balance())->toBe($class === LeaveAdjustment::class ? 12.0 : 8.0);
    expect(fn () => $record->fresh()->delete())->toThrow(ValidationException::class);
})->with([[LeaveAdjustment::class], [LeaveEncashment::class]]);

it('applies salary changes only after approval and recalculates totals', function () {
    [$user, $manager] = hrFlow(HrChangeRequest::class);
    Permission::findOrCreate('Update:EmpSalary', 'web');
    $user->givePermissionTo('Update:EmpSalary');
    $salary = EmpSalary::create(['employee_id' => $user->employee->id, 'basic_salary' => 100, 'hra' => 20, 'pf_deduction' => 5]);
    $request = app(HrChangeService::class)->submit('salary', $salary->id, ['basic_salary' => 200], 'Annual review');
    expect((float) $salary->fresh()->basic_salary)->toBe(100.0);
    expect(fn () => $salary->update(['basic_salary' => 300]))->toThrow(ValidationException::class);
    app(ApprovalService::class)->approve($request->approval, $manager->id);
    expect((float) $salary->fresh()->basic_salary)->toBe(200.0)
        ->and((float) $salary->fresh()->gross_salary)->toBe(220.0)
        ->and((float) $salary->fresh()->net_salary)->toBe(215.0)
        ->and($request->fresh()->applied_at)->not->toBeNull();
});

it('rolls back approval if the HR target changed since submission', function () {
    [$user, $manager] = hrFlow(HrChangeRequest::class);
    Permission::findOrCreate('Update:EmpSalary', 'web');
    $user->givePermissionTo('Update:EmpSalary');
    $salary = EmpSalary::create(['employee_id' => $user->employee->id, 'basic_salary' => 100]);
    $request = app(HrChangeService::class)->submit('salary', $salary->id, ['basic_salary' => 200], 'Review');
    $salary->forceFill(['basic_salary' => 150])->saveQuietly();
    expect(fn () => app(ApprovalService::class)->approve($request->approval, $manager->id))->toThrow(ValidationException::class);
    expect($request->approval->fresh()->approval_status)->toBe('pending')
        ->and((float) $salary->fresh()->basic_salary)->toBe(150.0);
});

it('waits for L2 before applying an HR change', function () {
    [$user, $manager] = hrFlow(HrChangeRequest::class);
    Permission::findOrCreate('Update:EmpSalary', 'web');
    $user->givePermissionTo('Update:EmpSalary');
    $director = reportingUser('HR Director');
    $role = JobRole::create(['name' => 'HR Director', 'code' => 'HRD', 'level' => 3]);
    $position = Position::create(['name' => 'HR Director', 'code' => 'HRDP', 'job_role_id' => $role->id]);
    $director->employee->positions()->attach($position->id);
    ApprovalFlowStep::create(['approval_flow_id' => ApprovalFlow::first()->id, 'step_order' => 2, 'job_role_id' => $role->id, 'territory_scope' => 'all']);
    $salary = EmpSalary::create(['employee_id' => $user->employee->id, 'basic_salary' => 100]);
    $request = app(HrChangeService::class)->submit('salary', $salary->id, ['basic_salary' => 200], 'Annual review');
    expect(app(ApprovalService::class)->approve($request->approval, $director->id))->toBeFalse();
    app(ApprovalService::class)->approve($request->approval, $manager->id);
    expect((float) $salary->fresh()->basic_salary)->toBe(100.0);
    app(ApprovalService::class)->approve($request->approval, $director->id);
    expect((float) $salary->fresh()->basic_salary)->toBe(200.0);
});

it('routes attendance overtime and comp-off changes without changing current values', function (string $type, array $values) {
    [$user, $manager] = hrFlow(HrChangeRequest::class);
    Permission::findOrCreate('Update:DailyAttendance', 'web');
    $user->givePermissionTo('Update:DailyAttendance');
    $attendance = DailyAttendance::create(['employee_id' => $user->employee->id, 'shift_master_id' => reportingShift('HR Shift')->id, 'attendance_date' => today(), 'status_id' => reportingAttendanceStatus('HR Present')->id]);
    $request = app(HrChangeService::class)->submit($type, $attendance->id, $values, 'Manager verified');
    foreach ($values as $key => $value) {
        expect((int) $attendance->fresh()->{$key})->toBe(0);
    }
    app(ApprovalService::class)->approve($request->approval, $manager->id);
    foreach ($values as $key => $value) {
        expect((int) $attendance->fresh()->{$key})->toBe($value);
    }
})->with([['attendance', ['final_working_minutes' => 480]], ['overtime', ['ot_minutes' => 60]], ['comp_off', ['comp_off_days' => 1]]]);

it('rejects changes to attendance already included in payroll', function () {
    [$user] = hrFlow(HrChangeRequest::class);
    Permission::findOrCreate('Update:DailyAttendance', 'web');
    $user->givePermissionTo('Update:DailyAttendance');
    $attendance = DailyAttendance::create(['employee_id' => $user->employee->id, 'shift_master_id' => reportingShift('Locked Shift')->id, 'attendance_date' => today(), 'status_id' => reportingAttendanceStatus('Locked Present')->id]);
    PayrollLeaveSnapshot::create(['employee_id' => $user->employee->id, 'leave_type_id' => reportingLeaveType('Locked Leave')->id, 'processed_till' => today(), 'opening_balance' => 10, 'closing_balance' => 10]);
    expect(fn () => app(HrChangeService::class)->submit('overtime', $attendance->id, ['ot_minutes' => 60], 'Locked'))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('hr_change_requests', 0);
});

it('applies an HR change immediately only when its approval setting is disabled', function () {
    $user = reportingUser('No HR Approval');
    $this->actingAs($user);
    Permission::findOrCreate('Update:EmpSalary', 'web');
    $user->givePermissionTo('Update:EmpSalary');
    $salary = EmpSalary::create(['employee_id' => $user->employee->id, 'basic_salary' => 100]);
    $request = app(HrChangeService::class)->submit('salary', $salary->id, ['basic_salary' => 200], 'Authorized correction');
    expect((float) $salary->fresh()->basic_salary)->toBe(200.0)->and($request->approval_status)->toBe('approved')->and($request->applied_at)->not->toBeNull();
    $this->assertDatabaseCount('approvals', 0);
});

it('requires target permission and rejects unsupported fields', function () {
    [$user] = hrFlow(HrChangeRequest::class);
    $salary = EmpSalary::create(['employee_id' => $user->employee->id, 'basic_salary' => 100]);
    expect(fn () => app(HrChangeService::class)->submit('salary', $salary->id, ['basic_salary' => 200], 'Unauthorized'))->toThrow(AuthorizationException::class);
    Permission::findOrCreate('Update:EmpSalary', 'web');
    $user->givePermissionTo('Update:EmpSalary');
    expect(fn () => app(HrChangeService::class)->submit('salary', $salary->id, ['employee_id' => 100], 'Wrong field'))->toThrow(ValidationException::class);
});

it('renders both HR request admin screens with the assigned permissions', function () {
    $user = reportingUser('HR Screens');
    $this->actingAs($user);
    foreach (['ViewAny:HrChangeRequest', 'View:HrChangeRequest', 'Create:HrChangeRequest', 'ViewAny:LeaveCancellation'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
    }
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(ManageHrChangeRequests::class)->assertSuccessful()->mountAction('create')->assertHasNoActionErrors();
    Livewire::test(ManageLeaveCancellations::class)->assertSuccessful();
});

it('keeps approved attendance corrections when punches are recalculated', function () {
    [$user, $manager] = hrFlow(HrChangeRequest::class);
    Permission::findOrCreate('Update:DailyAttendance', 'web');
    $user->givePermissionTo('Update:DailyAttendance');
    $status = reportingAttendanceStatus('Recalculated');
    $attendance = DailyAttendance::create(['employee_id' => $user->employee->id, 'shift_master_id' => reportingShift('Recalculated Shift')->id, 'attendance_date' => today(), 'status_id' => $status->id]);
    $normalizer = Mockery::mock(PunchNormalizationService::class);
    $normalizer->shouldReceive('normalize')->andReturn(['actual_working_minutes' => 480]);
    $calculator = Mockery::mock(AttendanceCalculationService::class);
    $calculator->shouldReceive('calculate')->andReturn(['final_working_minutes' => 480, 'ot_minutes' => 60, 'comp_off_days' => 1]);
    $resolver = Mockery::mock(AttendanceStatusResolver::class);
    $resolver->shouldReceive('resolve')->andReturn($status);
    app()->instance(AttendanceStatusResolver::class, $resolver);
    $job = new ProcessDailyAttendanceJob($attendance->id);
    $job->handle($normalizer, $calculator, $resolver);
    expect((int) $attendance->fresh()->ot_minutes)->toBe(0)->and((int) $attendance->fresh()->calculated_ot_minutes)->toBe(60);
    $request = app(HrChangeService::class)->submit('attendance', $attendance->id, ['final_working_minutes' => 420], 'Verified correction');
    app(ApprovalService::class)->approve($request->approval, $manager->id);
    $job->handle($normalizer, $calculator, $resolver);
    expect((int) $attendance->fresh()->final_working_minutes)->toBe(420);
});

it('prevents beneficiaries from approving HR requests submitted by someone else', function () {
    [$user, $manager] = hrFlow(HrChangeRequest::class);
    Permission::findOrCreate('Update:EmpSalary', 'web');
    $user->givePermissionTo('Update:EmpSalary');
    $salary = EmpSalary::create(['employee_id' => $manager->employee->id, 'basic_salary' => 100]);
    expect(fn () => app(HrChangeService::class)->submit('salary', $salary->id, ['basic_salary' => 200], 'Manager salary'))->toThrow(LogicException::class, 'themselves');
    $this->assertDatabaseCount('hr_change_requests', 0);
});

it('applies employee and employment changes through their approved requests', function (string $type) {
    [$user, $manager] = hrFlow(HrChangeRequest::class);
    Permission::findOrCreate('Update:Employee', 'web');
    $user->givePermissionTo('Update:Employee');
    if ($type === 'employee') {
        $target = $user->employee;
        $values = ['first_name' => 'Corrected name'];
    } else {
        $target = EmploymentDetail::create(['employee_id' => $user->employee->id, 'employment_status' => 'Active']);
        $values = ['remarks' => 'Probation review completed'];
    }
    $request = app(HrChangeService::class)->submit($type, $target->id, $values, 'HR review');
    foreach ($values as $key => $value) {
        expect($target->fresh()->{$key})->not->toBe($value);
    }
    app(ApprovalService::class)->approve($request->approval, $manager->id);
    foreach ($values as $key => $value) {
        expect($target->fresh()->{$key})->toBe($value);
    }
})->with(['employee', 'employment']);

it('records a half-day leave with approval disabled through the field service', function () {
    $user = reportingUser('Half Day');
    $this->actingAs($user);
    $type = reportingLeaveType('Half Day Leave');
    LeaveBalance::create(['employee_id' => $user->employee->id, 'leave_type_id' => $type->id, 'opening_balance' => 5, 'year_start_date' => today()->subYear(), 'year_end_date' => today()->addYear()]);
    $leave = app(FieldLeaveService::class)->apply($user, ['leave_type_id' => $type->id, 'from_date' => today()->addDays(3)->toDateString(), 'to_date' => today()->addDays(3)->toDateString(), 'is_half_day' => true, 'half_day_type' => 'first_half']);
    expect((float) $leave->total_days)->toBe(0.5)->and($leave->approval_status)->toBe('approved')->and((float) $leave->instances()->sum('pay_factor'))->toBe(0.5);
});
