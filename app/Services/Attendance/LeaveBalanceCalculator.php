<?php

namespace App\Services\Attendance;

use App\Models\LeaveAdjustment;
use App\Models\LeaveBalance;
use App\Models\LeaveEncashment;
use App\Models\LeaveInstance;
use App\Models\LeaveLapseRecord;
use App\Models\PayrollLeaveSnapshot;
use Carbon\Carbon;

class LeaveBalanceCalculator
{
    public function calculate(
        int $employeeId,
        int $leaveTypeId,
        ?Carbon $asOnDate = null,
        bool $usePayrollSnapshot = false
    ): array {

        $asOnDate ??= now();

        if ($usePayrollSnapshot) {
            return $this->calculateFromPayrollSnapshot(
                $employeeId,
                $leaveTypeId,
                $asOnDate
            );
        }

        return $this->calculateRealtime(
            $employeeId,
            $leaveTypeId,
            $asOnDate
        );
    }

    protected function calculateRealtime(
        int $employeeId,
        int $leaveTypeId,
        Carbon $asOnDate
    ): array {

        $balance = LeaveBalance::firstOrCreate(
            [
                'employee_id' => $employeeId,
                'leave_type_id' => $leaveTypeId,
            ],
            [
                'opening_balance' => 0,
                'year_start_date' => now()->startOfYear(),
                'year_end_date' => now()->endOfYear(),
            ]
        );

        $applied = LeaveInstance::approved()
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->whereDate('date', '<=', $asOnDate)
            ->sum('pay_factor');

        $adjustedPositive = LeaveAdjustment::approved()->positive()
            ->forEmployee($employeeId, $leaveTypeId)
            ->after($balance->year_start_date)
            ->whereDate('effective_date', '<=', $asOnDate)
            ->sum('days');

        $adjustedNegative = LeaveAdjustment::approved()->negative()
            ->forEmployee($employeeId, $leaveTypeId)
            ->after($balance->year_start_date)
            ->whereDate('effective_date', '<=', $asOnDate)
            ->sum('days');

        $encashed = LeaveEncashment::approved()->forEmployee($employeeId, $leaveTypeId)
            ->after($balance->year_start_date)
            ->whereDate('encashed_on', '<=', $asOnDate)
            ->sum('days');

        $lapsed = LeaveLapseRecord::forEmployee($employeeId, $leaveTypeId)
            ->after($balance->year_start_date)
            ->whereDate('lapsed_on', '<=', $asOnDate)
            ->sum('days');

        $closing =
            $balance->opening_balance
            + $adjustedPositive
            - $applied
            - $lapsed
            - $adjustedNegative
            - $encashed;

        return compact(
            'balance',
            'applied',
            'adjustedPositive',
            'adjustedNegative',
            'encashed',
            'lapsed',
            'closing'
        );
    }

    protected function calculateFromPayrollSnapshot(
        int $employeeId,
        int $leaveTypeId,
        Carbon $asOnDate
    ): array {

        $snapshot = PayrollLeaveSnapshot::latestForEmployee(
            $employeeId,
            $leaveTypeId
        );

        if (! $snapshot) {
            return $this->calculateRealtime(
                $employeeId,
                $leaveTypeId,
                $asOnDate
            );
        }

        $cutoffDate = $snapshot->processed_till;

        $applied = LeaveInstance::approved()
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->whereDate('date', '>', $cutoffDate)
            ->whereDate('date', '<=', $asOnDate)
            ->sum('pay_factor');

        $adjustedPositive = LeaveAdjustment::approved()->positive()
            ->forEmployee($employeeId, $leaveTypeId)
            ->after($cutoffDate)
            ->whereDate('effective_date', '<=', $asOnDate)
            ->sum('days');

        $adjustedNegative = LeaveAdjustment::approved()->negative()
            ->forEmployee($employeeId, $leaveTypeId)
            ->after($cutoffDate)
            ->whereDate('effective_date', '<=', $asOnDate)
            ->sum('days');

        $encashed = LeaveEncashment::approved()->forEmployee($employeeId, $leaveTypeId)
            ->after($cutoffDate)
            ->whereDate('encashed_on', '<=', $asOnDate)
            ->sum('days');

        $lapsed = LeaveLapseRecord::forEmployee($employeeId, $leaveTypeId)
            ->after($cutoffDate)
            ->whereDate('lapsed_on', '<=', $asOnDate)
            ->sum('days');

        $closing =
            $snapshot->closing_balance
            + $adjustedPositive
            - $applied
            - $lapsed
            - $adjustedNegative
            - $encashed;

        return compact(
            'snapshot',
            'applied',
            'adjustedPositive',
            'adjustedNegative',
            'encashed',
            'lapsed',
            'closing'
        );
    }
}
