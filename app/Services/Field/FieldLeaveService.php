<?php

namespace App\Services\Field;

use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveType;
use App\Models\User;
use App\Orchestrators\LeaveApplicationOrchestrator;
use App\Services\Attendance\LeaveBalanceCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use LogicException;

class FieldLeaveService
{
    public function __construct(
        private LeaveApplicationOrchestrator $leave,
        private LeaveBalanceCalculator $balances,
    ) {}

    /**
     * @return Collection<int, LeaveType>
     */
    public function types(User $user): Collection
    {
        $employee = $this->requireEmployee($user);

        return LeaveType::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->each(function (LeaveType $type) use ($employee): void {
                $closing = $this->balances->calculate(
                    employeeId: $employee->id,
                    leaveTypeId: $type->id,
                )['closing'] ?? 0;

                $type->setAttribute('balance', (float) $closing);
            });
    }

    /**
     * @return Collection<int, LeaveApplication>
     */
    public function applications(User $user): Collection
    {
        $employee = $this->requireEmployee($user);

        return LeaveApplication::query()
            ->with(['leaveType', 'latestCancellation'])
            ->where('employee_id', $employee->id)
            ->orderByDesc('from_date')
            ->orderByDesc('id')
            ->limit(100)
            ->get();
    }

    /**
     * @param  array{leave_type_id: int, from_date: string, to_date: string, is_half_day?: bool, half_day_type?: string|null, reason?: string|null}  $data
     */
    public function apply(User $user, array $data): LeaveApplication
    {
        $employee = $this->requireEmployee($user);
        $type = LeaveType::query()->whereKey($data['leave_type_id'])->where('is_active', true)->first();

        if (! $type) {
            throw ValidationException::withMessages([
                'leave_type_id' => 'Choose an active leave type.',
            ]);
        }

        $from = Carbon::parse($data['from_date'])->startOfDay();
        $to = Carbon::parse($data['to_date'])->startOfDay();
        $halfDay = (bool) ($data['is_half_day'] ?? false);

        if ($halfDay && ! $from->equalTo($to)) {
            throw ValidationException::withMessages([
                'to_date' => 'A half day must start and end on the same date.',
            ]);
        }

        if ($halfDay && ! in_array($data['half_day_type'] ?? null, ['first_half', 'second_half'], true)) {
            throw ValidationException::withMessages([
                'half_day_type' => 'Choose the first or second half.',
            ]);
        }

        $days = $halfDay ? 0.5 : ((int) $from->diffInDays($to)) + 1;

        try {
            $application = $this->leave->apply([
                'employee_id' => $employee->id,
                'leave_type_code' => $type->code,
                'from_date' => $from->toDateString(),
                'to_date' => $to->toDateString(),
                'days' => $days,
                'is_half_day' => $halfDay,
                'half_day_type' => $halfDay ? $data['half_day_type'] : null,
                'reason' => $data['reason'] ?? null,
            ]);
        } catch (LogicException $exception) {
            throw ValidationException::withMessages([
                'leave' => $exception->getMessage(),
            ]);
        }

        return $application->fresh(['leaveType', 'latestCancellation']);
    }

    public function cancel(User $user, int $applicationId): LeaveApplication
    {
        $employee = $this->requireEmployee($user);
        $application = LeaveApplication::query()->with(['leaveType', 'latestCancellation'])->find($applicationId);

        if (! $application || (int) $application->employee_id !== (int) $employee->id) {
            abort(404, 'Leave application not found.');
        }

        if (! self::canCancel($application)) {
            throw ValidationException::withMessages([
                'leave' => 'This leave request can no longer be cancelled.',
            ]);
        }

        try {
            $this->leave->cancel($application->id, $user->id);
        } catch (LogicException $exception) {
            throw ValidationException::withMessages(['leave' => $exception->getMessage()]);
        }

        return $application->fresh(['leaveType', 'latestCancellation']);
    }

    public static function canCancel(LeaveApplication $application): bool
    {
        return ! $application->payroll_locked
            && in_array($application->approval_status, ['draft', 'pending', 'applied', 'approved'], true)
            && ! in_array($application->latestCancellation?->approval_status, ['draft', 'pending'], true);
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
}
