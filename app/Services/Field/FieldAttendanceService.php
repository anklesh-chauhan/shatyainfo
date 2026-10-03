<?php

namespace App\Services\Field;

use App\Jobs\ProcessDailyAttendanceJob;
use App\Models\AttendancePunch;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\EmployeeAttendanceStatus;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FieldAttendanceService
{
    /**
     * @param  array{latitude: float|int|string, longitude: float|int|string, source?: string|null, device_id?: string|null}  $data
     */
    public function punchIn(User $user, array $data): DailyAttendance
    {
        $employee = $this->requireEmployee($user);
        $this->requireCoordinates($data);
        $recordedAt = FieldRecordedAt::resolve($data);

        return DB::transaction(function () use ($user, $employee, $data, $recordedAt): DailyAttendance {
            $existing = DailyAttendance::query()
                ->where('employee_id', $employee->id)
                ->whereDate('attendance_date', $recordedAt->startOfDay())
                ->first();

            if ($existing) {
                throw ValidationException::withMessages([
                    'attendance' => 'Already punched in for today.',
                ]);
            }

            $shift = $employee->currentShiftForDate($recordedAt->startOfDay());

            if (! $shift) {
                throw ValidationException::withMessages([
                    'shift' => 'No active shift is assigned for today. Please contact HR.',
                ]);
            }

            $firstPunchIn = $recordedAt->format('H:i');

            $attendance = DailyAttendance::query()->create([
                'employee_id' => $employee->id,
                'shift_master_id' => $shift->id,
                'attendance_date' => $recordedAt->startOfDay(),
                'first_punch_in' => $firstPunchIn,
                'status_id' => $this->singlePunchStatusId(),
            ]);

            AttendancePunch::query()->create([
                'employee_id' => $employee->id,
                'punch_date' => $recordedAt->startOfDay(),
                'punch_time' => $firstPunchIn,
                'punch_type' => 'in',
                'source' => $data['source'] ?? 'field-api',
                'device_id' => $data['device_id'] ?? null,
                'raw_payload' => [
                    'recorded_at' => $recordedAt->toIso8601String(),
                    'received_at' => now()->toIso8601String(),
                    'user_id' => $user->id,
                    'latitude' => (float) $data['latitude'],
                    'longitude' => (float) $data['longitude'],
                ],
            ]);

            return $attendance->fresh(['status', 'shift']);
        });
    }

    /**
     * @param  array{latitude: float|int|string, longitude: float|int|string, source?: string|null, device_id?: string|null}  $data
     */
    public function punchOut(User $user, array $data): DailyAttendance
    {
        $employee = $this->requireEmployee($user);
        $this->requireCoordinates($data);
        $recordedAt = FieldRecordedAt::resolve($data);

        return DB::transaction(function () use ($user, $employee, $data, $recordedAt): DailyAttendance {
            $attendance = DailyAttendance::query()
                ->where('employee_id', $employee->id)
                ->whereDate('attendance_date', $recordedAt->startOfDay())
                ->first();

            if (! $attendance) {
                throw ValidationException::withMessages([
                    'attendance' => 'No attendance record found for today.',
                ]);
            }

            $alreadyOut = $attendance->punches()
                ->where('punch_type', 'out')
                ->exists();

            if ($attendance->first_punch_in && $recordedAt->format('H:i') < $attendance->first_punch_in->format('H:i')) {
                throw ValidationException::withMessages(['recorded_at' => 'Punch-out cannot be before punch-in.']);
            }

            if ($alreadyOut) {
                throw ValidationException::withMessages([
                    'attendance' => 'Already punched out for today.',
                ]);
            }

            AttendancePunch::query()->create([
                'employee_id' => $employee->id,
                'punch_date' => $recordedAt->startOfDay(),
                'punch_time' => $recordedAt->format('H:i'),
                'punch_type' => 'out',
                'source' => $data['source'] ?? 'field-api',
                'device_id' => $data['device_id'] ?? null,
                'raw_payload' => [
                    'recorded_at' => $recordedAt->toIso8601String(),
                    'received_at' => now()->toIso8601String(),
                    'user_id' => $user->id,
                    'latitude' => (float) $data['latitude'],
                    'longitude' => (float) $data['longitude'],
                ],
            ]);

            $this->ensureStatus('DP', 'Day Present');
            $this->ensureStatus('HD', 'Half Day');
            $this->ensureStatus('ABS', 'Absent');
            $this->ensureStatus('SP', 'Single Punch');

            // Call handle directly so landlord/admin/API hosts without a current
            // tenant are not blocked by Spatie TenantAware queue wrapping.
            app()->call([new ProcessDailyAttendanceJob($attendance->id), 'handle']);

            return $attendance->fresh(['status', 'shift']);
        });
    }

    public function today(User $user): ?DailyAttendance
    {
        $employee = $user->employee;

        if (! $employee) {
            return null;
        }

        return DailyAttendance::query()
            ->with(['status', 'shift'])
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', today())
            ->first();
    }

    /**
     * @return Collection<int, DailyAttendance>
     */
    public function forMonth(User $user, string $month): Collection
    {
        $employee = $user->employee;

        if (! $employee) {
            return collect();
        }

        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();

        return DailyAttendance::query()
            ->with(['status', 'shift'])
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', '>=', $start->toDateString())
            ->whereDate('attendance_date', '<=', $start->copy()->endOfMonth()->toDateString())
            ->orderBy('attendance_date')
            ->get();
    }

    private function requireEmployee(User $user): Employee
    {
        $employee = $user->employee;

        if (! $employee) {
            throw ValidationException::withMessages([
                'employee' => 'Employee not linked to this user.',
            ]);
        }

        return $employee;
    }

    /**
     * @param  array{latitude?: mixed, longitude?: mixed}  $data
     */
    private function requireCoordinates(array $data): void
    {
        if (! isset($data['latitude'], $data['longitude'])) {
            throw ValidationException::withMessages([
                'gps' => 'GPS location is required.',
            ]);
        }
    }

    private function singlePunchStatusId(): int
    {
        return $this->ensureStatus('SP', 'Single Punch')->id;
    }

    private function ensureStatus(string $code, string $label): EmployeeAttendanceStatus
    {
        return EmployeeAttendanceStatus::query()->firstOrCreate(
            ['status_code' => $code],
            [
                'status' => $label,
                'remarks' => $label,
            ],
        );
    }
}
