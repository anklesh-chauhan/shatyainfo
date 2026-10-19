<?php

namespace App\Services\HR;

use App\Models\ApprovalSetting;
use App\Models\DailyAttendance;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmploymentDetail;
use App\Models\EmpSalary;
use App\Models\HrChangeRequest;
use App\Models\PayrollLeaveSnapshot;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class HrChangeService
{
    private static int $authorizedWrites = 0;

    public static function types(): array
    {
        return ['salary' => 'Salary change', 'employment' => 'Employment change', 'employee' => 'Employee details', 'attendance' => 'Daily attendance correction', 'legacy_attendance' => 'Employee attendance correction', 'overtime' => 'Overtime authorization', 'comp_off' => 'Comp-off authorization'];
    }

    public static function modelClass(string $type): string
    {
        return match ($type) {
            'salary' => EmpSalary::class,
            'employment' => EmploymentDetail::class,
            'employee' => Employee::class,
            'legacy_attendance' => EmployeeAttendance::class,
            'attendance', 'overtime', 'comp_off' => DailyAttendance::class,
            default => throw ValidationException::withMessages(['request_type' => 'Choose a supported HR change.']),
        };
    }

    public static function rules(string $type): array
    {
        return match ($type) {
            'salary' => array_fill_keys(['basic_salary', 'hra', 'conveyance', 'special_allowance', 'medical_allowance', 'other_allowances', 'pf_deduction', 'esic_deduction', 'professional_tax_deduction'], 'nullable|numeric|min:0|max:9999999999'),
            'employment' => ['employment_type' => 'nullable|in:Permanent,Contract,Part-Time,Intern,Temporary,Consultant', 'employment_status' => 'nullable|in:Active,Inactive,Terminated,Retired,On Leave', 'resign_offer_date' => 'nullable|date', 'last_working_date' => 'nullable|date', 'probation_date' => 'nullable|date', 'confirm_date' => 'nullable|date', 'fnf_retiring_date' => 'nullable|date', 'last_increment_date' => 'nullable|date', 'remarks' => 'nullable|string|max:2000'],
            'employee' => ['first_name' => 'string|max:100', 'last_name' => 'nullable|string|max:100', 'mobile_number' => 'nullable|string|max:30', 'emergency_contact_name' => 'nullable|string|max:100', 'emergency_contact_number' => 'nullable|string|max:30', 'is_active' => 'boolean'],
            'attendance' => ['final_working_minutes' => 'integer|min:0|max:1440', 'is_absent' => 'boolean', 'is_half_day' => 'boolean', 'status_id' => 'integer|exists:employee_attendance_statuses,id'],
            'legacy_attendance' => ['check_in' => 'date', 'check_out' => 'date', 'status_id' => 'integer|exists:employee_attendance_statuses,id'],
            'overtime' => ['ot_minutes' => 'integer|min:0|max:1440'],
            'comp_off' => ['comp_off_days' => 'integer|min:0|max:1'],
            default => [],
        };
    }

    public function submit(string $type, int $targetId, array $values, string $reason): HrChangeRequest
    {
        return DB::transaction(function () use ($type, $targetId, $values, $reason): HrChangeRequest {
            $class = self::modelClass($type);
            $target = $class::query()->lockForUpdate()->findOrFail($targetId);
            Gate::authorize('update', $target);
            Validator::make(['reason' => $reason], ['reason' => 'required|string|max:2000'])->validate();
            if ($values === [] || array_diff(array_keys($values), array_keys(self::rules($type))) !== []) {
                throw ValidationException::withMessages(['proposed_values' => 'Provide only the supported fields for this request type.']);
            }
            Validator::make($values, array_intersect_key(self::rules($type), $values))->validate();
            if ($type === 'legacy_attendance' && Carbon::parse($values['check_out'] ?? $target->check_out)->lte(Carbon::parse($values['check_in'] ?? $target->check_in))) {
                throw ValidationException::withMessages(['proposed_values' => 'Check-out must be after check-in.']);
            }
            $employeeId = $target instanceof Employee ? $target->id : $target->employee_id;
            $this->assertPayrollUnlocked($target, $employeeId);
            if (HrChangeRequest::query()->where('target_type', $class)->where('target_id', $targetId)->whereIn('approval_status', ['draft', 'pending'])->exists()) {
                throw ValidationException::withMessages(['target_id' => 'This record already has a pending HR change.']);
            }
            $previous = array_intersect_key($target->getRawOriginal(), $values);
            $request = HrChangeRequest::create([
                'employee_id' => $employeeId, 'request_type' => $type,
                'target_type' => $class, 'target_id' => $targetId,
                'previous_values' => $previous, 'proposed_values' => $values, 'reason' => $reason,
            ]);
            if (ApprovalSetting::moduleRequiresApproval(HrChangeRequest::class)) {
                $request->startApproval();
            } else {
                $this->apply($request);
            }

            return $request->refresh();
        });
    }

    public function apply(HrChangeRequest $request): void
    {
        if ($request->applied_at) {
            return;
        }
        $class = self::modelClass($request->request_type);
        if ($request->target_type !== $class) {
            throw ValidationException::withMessages(['target' => 'The HR request target does not match its type.']);
        }
        $target = $class::query()->lockForUpdate()->findOrFail($request->target_id);
        $employeeId = $target instanceof Employee ? $target->id : $target->employee_id;
        if ((int) $employeeId !== (int) $request->employee_id) {
            throw ValidationException::withMessages(['approval' => 'The employee linked to this HR record changed after submission.']);
        }
        $this->assertPayrollUnlocked($target, $request->employee_id);
        if (array_intersect_key($target->getRawOriginal(), $request->previous_values) != $request->previous_values) {
            throw ValidationException::withMessages(['approval' => 'The HR record changed since submission. Reject this request and submit the current values again.']);
        }
        self::authorizedUpdate(function () use ($target, $request): void {
            $target->fill($request->proposed_values);
            if ($target instanceof EmpSalary) {
                $target->gross_salary = collect(['basic_salary', 'hra', 'conveyance', 'special_allowance', 'medical_allowance', 'other_allowances'])->sum(fn (string $field): float => (float) $target->{$field});
                $target->net_salary = $target->gross_salary - (float) $target->pf_deduction - (float) $target->esic_deduction - (float) $target->professional_tax_deduction;
            }
            $target->save();
        });
        $request->forceFill(['applied_at' => now()])->saveQuietly();
    }

    public static function authorizedUpdate(callable $callback): mixed
    {
        self::$authorizedWrites++;
        try {
            return $callback();
        } finally {
            self::$authorizedWrites--;
        }
    }

    public static function isAuthorizedUpdate(): bool
    {
        return self::$authorizedWrites > 0;
    }

    private function assertPayrollUnlocked(Model $target, int $employeeId): void
    {
        if ($target instanceof DailyAttendance || $target instanceof EmployeeAttendance) {
            if (PayrollLeaveSnapshot::query()->where('employee_id', $employeeId)->whereDate('processed_till', '>=', $target->attendance_date)->exists()) {
                throw ValidationException::withMessages(['attendance' => 'Attendance included in processed payroll cannot be changed.']);
            }
        }
    }
}
