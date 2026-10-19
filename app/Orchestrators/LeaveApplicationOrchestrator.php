<?php

namespace App\Orchestrators;

use App\Models\ApprovalSetting;
use App\Models\LeaveApplication;
use App\Models\LeaveCancellation;
use App\Models\LeaveInstance;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\Approval\HrApprovalHandler;
use App\Services\Attendance\LeaveBalanceCalculator;
use App\Services\Attendance\LeaveDateValidator;
use App\Services\Attendance\LeaveRuleEvaluatorService;
use App\Services\Attendance\LeaveWorkflowService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveApplicationOrchestrator
{
    /**
     * Apply for leave
     */
    public function apply(array $data): LeaveApplication
    {
        $from = Carbon::parse($data['from_date'])->startOfDay();
        $to = Carbon::parse($data['to_date'])->startOfDay();
        $halfDay = (bool) ($data['is_half_day'] ?? false);
        if ($to->lt($from) || ($halfDay && (! $from->equalTo($to) || ! in_array($data['half_day_type'] ?? null, ['first_half', 'second_half'], true)))) {
            throw ValidationException::withMessages(['leave' => 'Choose a valid date range and half-day period.']);
        }
        $data['days'] = $halfDay ? 0.5 : $from->diffInDays($to) + 1;

        return DB::transaction(function () use ($data) {

            $leaveType = LeaveType::where('code', $data['leave_type_code'])->firstOrFail();

            // 1️⃣ Build rule context
            $context = $this->buildContext($data, $leaveType);

            // 2️⃣ Rule evaluation
            $ruleResult = app(LeaveRuleEvaluatorService::class)->evaluate($context);

            if ($ruleResult['denied']) {
                throw ValidationException::withMessages([
                    'leave' => $ruleResult['errors'],
                ]);
            }

            // 3️⃣ Balance check
            $balance = app(LeaveBalanceCalculator::class)->calculate(
                employeeId: $data['employee_id'],
                leaveTypeId: $leaveType->id,
                asOnDate: Carbon::parse($data['from_date']),
                usePayrollSnapshot: $ruleResult['payroll']['use_payroll_snapshot'] ?? false
            );

            if ($balance['closing'] < $data['days']) {
                throw ValidationException::withMessages([
                    'leave' => ['Insufficient leave balance'],
                ]);
            }

            // 4️⃣ Create leave application (authoritative record)
            $application = LeaveApplication::create([
                'employee_id' => $data['employee_id'],
                'leave_type_id' => $leaveType->id,
                'from_date' => $data['from_date'],
                'to_date' => $data['to_date'],
                'is_half_day' => $data['is_half_day'] ?? false,
                'half_day_type' => $data['half_day_type'] ?? null,
                'reason' => $data['reason'] ?? null,
                'total_days' => $data['days'],
                'substitute_user_id' => $data['substitute_user_id'] ?? null,
                'approval_status' => 'draft',

            ]);

            // Validate dates (duplicate / holiday / weekoff)
            app(LeaveDateValidator::class)->validate(
                employeeId: $application->employee_id,
                from: Carbon::parse($application->from_date),
                to: Carbon::parse($application->to_date)
            );

            // 5️⃣ Generate leave instances
            $this->generateInstances($application, $ruleResult);

            // 6️⃣ Start workflow (this sends notifications internally)
            app(LeaveWorkflowService::class)->start(
                $application,
                $ruleResult
            );

            return $application;
        });
    }

    /**
     * Cancel / revoke leave
     */
    public function cancel(int $applicationId, int $userId): void
    {
        DB::transaction(function () use ($applicationId, $userId): void {
            $application = LeaveApplication::query()->lockForUpdate()->findOrFail($applicationId);
            $user = User::findOrFail($userId);
            abort_unless((int) $application->employee_id === (int) $user->employee?->id || $user->can('update', $application), 403);
            if ($application->payroll_locked || ! in_array($application->approval_status, ['draft', 'pending', 'applied', 'approved'], true)) {
                throw ValidationException::withMessages(['leave' => 'This leave cannot be cancelled.']);
            }
            if ($application->approval_status !== 'approved') {
                if ($approval = $application->approval()->first()) {
                    app(ApprovalService::class)->cancel($approval);
                }
                $application->forceFill(['approval_status' => 'cancelled', 'revoked_at' => now()])->saveQuietly();
                $application->instances()->update(['approval_status' => 'cancelled']);

                return;
            }
            if (LeaveCancellation::query()->where('leave_application_id', $application->id)->whereIn('approval_status', ['draft', 'pending'])->exists()) {
                throw ValidationException::withMessages(['leave' => 'A cancellation request is already pending.']);
            }
            $request = LeaveCancellation::create([
                'leave_application_id' => $application->id,
                'employee_id' => $application->employee_id,
                'reason' => 'Leave cancellation requested',
            ]);
            if (ApprovalSetting::moduleRequiresApproval(LeaveCancellation::class)) {
                $request->startApproval();
            } else {
                app(HrApprovalHandler::class)->apply($request);
            }
        });
    }

    /**
     * Build rule evaluation context
     */
    protected function buildContext(array $data, LeaveType $leaveType): array
    {
        return [
            'employee_id' => $data['employee_id'],
            'leave_type_code' => $leaveType->code,
            'from_date' => $data['from_date'],
            'to_date' => $data['to_date'],
            'days' => $data['days'],
            'is_half_day' => $data['is_half_day'] ?? false,
            'half_day_type' => $data['half_day_type'] ?? null,
            'previous_day_leave' => $data['previous_day_leave'] ?? null,
            'attendance' => $data['attendance'] ?? null,
            'notice_period' => $data['notice_period'] ?? false,
            'event' => 'LEAVE_APPLIED',
            'role' => 'employee',
            'future_leave' => Carbon::parse($data['from_date'])->isFuture(),
        ];
    }

    /**
     * Generate date-wise leave instances
     */
    protected function generateInstances(
        LeaveApplication $application,
        array $ruleResult
    ): void {

        $payFactor = $ruleResult['computation']['pay_factor'] ?? 1;

        foreach (
            Carbon::parse($application->from_date)
                ->daysUntil($application->to_date) as $date
        ) {
            LeaveInstance::create([
                'leave_application_id' => $application->id,
                'employee_id' => $application->employee_id,
                'leave_type_id' => $application->leave_type_id,
                'date' => $date,
                'pay_factor' => $application->is_half_day ? 0.5 : $payFactor,
                'is_half_day' => $application->is_half_day,
                'approval_status' => 'applied',
            ]);
        }
    }
}
