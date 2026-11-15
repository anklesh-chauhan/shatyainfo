<?php

use App\Models\AttendancePunch;
use App\Models\DailyAttendance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('punches in with gps and creates daily attendance', function () {
    $user = reportingUser('PunchApi');
    $shift = reportingShift('Field Shift');
    reportingAssignShift($user, $shift);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/attendance/punch-in', [
        'latitude' => 19.0760,
        'longitude' => 72.8777,
        'source' => 'flutter',
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.employee_id', $user->employee->id);

    expect(DailyAttendance::query()->where('employee_id', $user->employee->id)->count())->toBe(1)
        ->and(AttendancePunch::query()->where('employee_id', $user->employee->id)->where('punch_type', 'in')->count())->toBe(1);
});

it('rejects punch in without a linked employee', function () {
    $user = User::factory()->create();

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/attendance/punch-in', [
        'latitude' => 19.0760,
        'longitude' => 72.8777,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['employee']);

    expect(DailyAttendance::query()->count())->toBe(0);
});

it('rejects punch in without a shift', function () {
    $user = reportingUser('NoShiftApi');

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/attendance/punch-in', [
        'latitude' => 19.0760,
        'longitude' => 72.8777,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['shift']);

    expect(DailyAttendance::query()->where('employee_id', $user->employee->id)->count())->toBe(0);
});

it('rejects a second punch in for the same day', function () {
    $user = reportingUser('SecondPunchApi');
    $shift = reportingShift('Second Shift');
    reportingAssignShift($user, $shift);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/attendance/punch-in', [
        'latitude' => 19.0760,
        'longitude' => 72.8777,
    ])->assertSuccessful();

    $this->postJson('/api/v1/attendance/punch-in', [
        'latitude' => 19.0760,
        'longitude' => 72.8777,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['attendance']);

    expect(DailyAttendance::query()->where('employee_id', $user->employee->id)->count())->toBe(1)
        ->and(AttendancePunch::query()->where('employee_id', $user->employee->id)->where('punch_type', 'in')->count())->toBe(1);
});

it('rejects a punch out before punch in', function () {
    $user = reportingUser('EarlyOutApi');
    $shift = reportingShift('Early Shift');
    reportingAssignShift($user, $shift);
    $inAt = now()->subDay()->setTime(15, 0, 0);
    $outAt = now()->subDay()->setTime(14, 0, 0);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/attendance/punch-in', [
        'latitude' => 19.0760,
        'longitude' => 72.8777,
        'recorded_at' => $inAt->format('Y-m-d\TH:i:sP'),
    ])->assertSuccessful();

    $this->postJson('/api/v1/attendance/punch-out', [
        'latitude' => 19.0800,
        'longitude' => 72.8800,
        'recorded_at' => $outAt->format('Y-m-d\TH:i:sP'),
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['recorded_at']);

    expect(AttendancePunch::query()->where('employee_id', $user->employee->id)->where('punch_type', 'out')->count())->toBe(0);
});

it('punches out after punch in', function () {
    $user = reportingUser('PunchOutApi');
    $shift = reportingShift('Out Shift');
    reportingAssignShift($user, $shift);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/attendance/punch-in', [
        'latitude' => 19.0760,
        'longitude' => 72.8777,
    ])->assertSuccessful();

    $this->postJson('/api/v1/attendance/punch-out', [
        'latitude' => 19.0800,
        'longitude' => 72.8800,
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.employee_id', $user->employee->id);

    expect(AttendancePunch::query()->where('employee_id', $user->employee->id)->where('punch_type', 'out')->count())->toBe(1);
});

it('returns today attendance', function () {
    $user = reportingUser('TodayAttApi');
    $shift = reportingShift('Today Shift');
    reportingAssignShift($user, $shift);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/attendance/today')
        ->assertSuccessful()
        ->assertJsonPath('data', null);

    $this->postJson('/api/v1/attendance/punch-in', [
        'latitude' => 19.0760,
        'longitude' => 72.8777,
    ])->assertSuccessful();

    $this->getJson('/api/v1/attendance/today')
        ->assertSuccessful()
        ->assertJsonPath('data.employee_id', $user->employee->id);
});
