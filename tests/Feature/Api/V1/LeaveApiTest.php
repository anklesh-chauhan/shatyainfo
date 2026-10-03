<?php

use App\Models\ApprovalFlow;
use App\Models\ApprovalSetting;
use App\Models\DailyAttendance;
use App\Models\LeaveApplication;
use App\Models\LeaveBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function enableLeaveApproval(): void
{
    ApprovalSetting::query()->update(['enabled_modules' => ['LeaveApplication']]);

    if (! ApprovalSetting::query()->exists()) {
        ApprovalSetting::query()->create([
            'enabled_modules' => ['LeaveApplication'],
        ]);
    }

    ApprovalFlow::query()->create([
        'module' => 'LeaveApplication',
        'active' => true,
        'condition_type' => 'amount',
        'version' => 1,
        'priority' => 1,
    ]);
}

it('lists a month of attendance for the signed-in employee', function () {
    $user = reportingUser('MonthAtt');
    $shift = reportingShift('Month Shift');
    reportingAssignShift($user, $shift);

    $status = reportingAttendanceStatus('Present');

    DailyAttendance::query()->create([
        'employee_id' => $user->employee->id,
        'shift_master_id' => $shift->id,
        'attendance_date' => '2026-09-15',
        'first_punch_in' => '09:05',
        'last_punch_out' => '18:10',
        'status_id' => $status->id,
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/attendance?month=2026-09')
        ->assertSuccessful()
        ->assertJsonPath('data.0.attendance_date', '2026-09-15')
        ->assertJsonPath('data.0.first_punch_in', '09:05')
        ->assertJsonPath('data.0.last_punch_out', '18:10');

    $this->getJson('/api/v1/attendance?month=2026-08')
        ->assertSuccessful()
        ->assertJsonCount(0, 'data');
});

it('shows leave balances and files a request for the signed-in employee', function () {
    $user = reportingUser('LeaveApi');
    $type = reportingLeaveType('Casual');

    LeaveBalance::query()->create([
        'employee_id' => $user->employee->id,
        'leave_type_id' => $type->id,
        'opening_balance' => 6,
        'year_start_date' => now()->startOfYear(),
        'year_end_date' => now()->endOfYear(),
    ]);

    enableLeaveApproval();

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/leave-types')
        ->assertSuccessful()
        ->assertJsonPath('data.0.id', $type->id)
        ->assertJsonPath('data.0.balance', 6);

    $date = today()->addDays(10)->toDateString();

    $this->postJson('/api/v1/leave-applications', [
        'leave_type_id' => $type->id,
        'from_date' => $date,
        'to_date' => $date,
        'reason' => 'Family function',
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.total_days', 1)
        ->assertJsonPath('data.leave_type.id', $type->id);

    $application = LeaveApplication::query()->where('employee_id', $user->employee->id)->first();
    expect($application)->not->toBeNull()
        ->and((float) $application->total_days)->toBe(1.0);

    $this->getJson('/api/v1/leave-applications')
        ->assertSuccessful()
        ->assertJsonPath('data.0.id', $application->id);

    $draft = LeaveApplication::query()->create([
        'employee_id' => $user->employee->id,
        'leave_type_id' => $type->id,
        'from_date' => today()->addDays(21)->toDateString(),
        'to_date' => today()->addDays(21)->toDateString(),
        'total_days' => 1,
        'approval_status' => 'draft',
        'reason' => 'Clinic visit',
    ]);

    $this->postJson('/api/v1/leave-applications/'.$draft->id.'/cancel')
        ->assertSuccessful()
        ->assertJsonPath('data.approval_status', 'cancelled')
        ->assertJsonPath('data.can_cancel', false);

    expect($draft->fresh()->approval_status)->toBe('cancelled');
});

it('rejects leave when the balance is too small', function () {
    $user = reportingUser('NoBalance');
    $type = reportingLeaveType('Sick');

    enableLeaveApproval();

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/leave-applications', [
        'leave_type_id' => $type->id,
        'from_date' => today()->addDay()->toDateString(),
        'to_date' => today()->addDay()->toDateString(),
    ])->assertUnprocessable()
        ->assertJsonPath('errors.leave.0', 'Insufficient leave balance');
});
