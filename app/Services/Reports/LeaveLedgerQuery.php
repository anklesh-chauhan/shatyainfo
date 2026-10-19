<?php

namespace App\Services\Reports;

use App\Models\LeaveLedgerEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class LeaveLedgerQuery
{
    public function builder(): Builder
    {
        return ReportVisibility::constrainToVisibleUsers(
            LeaveLedgerEntry::query()
                ->fromSub($this->unionQuery(), 'leave_ledger')
                ->with(['leaveType', 'employee'])
        );
    }

    public function unionQuery()
    {
        return DB::query()
            ->fromSub(
                DB::query()
                    ->from('leave_instances')
                    ->join('employees', 'employees.id', '=', 'leave_instances.employee_id')
                    ->join('users', 'users.id', '=', 'employees.login_id')
                    ->whereNull('leave_instances.deleted_at')
                    ->where('leave_instances.approval_status', 'approved')
                    ->selectRaw("
                        leave_instances.employee_id,
                        employees.login_id as user_id,
                        leave_instances.leave_type_id,
                        users.name as employee_name,
                        leave_instances.date,
                        'Leave Applied' as source,
                        -leave_instances.pay_factor as amount
                    ")
                    ->unionAll(
                        DB::table('leave_adjustments')
                            ->join('employees', 'employees.id', '=', 'leave_adjustments.employee_id')
                            ->join('users', 'users.id', '=', 'employees.login_id')
                            ->whereNull('leave_adjustments.deleted_at')
                            ->where('leave_adjustments.approval_status', 'approved')
                            ->selectRaw("
                                leave_adjustments.employee_id,
                                employees.login_id as user_id,
                                leave_adjustments.leave_type_id,
                                users.name as employee_name,
                                leave_adjustments.effective_date as date,
                                leave_adjustments.reason as source,
                                CASE
                                    WHEN leave_adjustments.type = 'positive' THEN leave_adjustments.days
                                    ELSE -leave_adjustments.days
                                END as amount
                            ")
                    )
                    ->unionAll(
                        DB::table('leave_encashments')
                            ->join('employees', 'employees.id', '=', 'leave_encashments.employee_id')
                            ->join('users', 'users.id', '=', 'employees.login_id')
                            ->whereNull('leave_encashments.deleted_at')
                            ->where('leave_encashments.approval_status', 'approved')
                            ->selectRaw("
                                leave_encashments.employee_id,
                                employees.login_id as user_id,
                                leave_encashments.leave_type_id,
                                users.name as employee_name,
                                leave_encashments.encashed_on as date,
                                'Encashment' as source,
                                -leave_encashments.days as amount
                            ")
                    )
                    ->unionAll(
                        DB::table('leave_lapse_records')
                            ->join('employees', 'employees.id', '=', 'leave_lapse_records.employee_id')
                            ->join('users', 'users.id', '=', 'employees.login_id')
                            ->whereNull('leave_lapse_records.deleted_at')
                            ->selectRaw("
                                leave_lapse_records.employee_id,
                                employees.login_id as user_id,
                                leave_lapse_records.leave_type_id,
                                users.name as employee_name,
                                leave_lapse_records.lapsed_on as date,
                                'Lapse' as source,
                                -leave_lapse_records.days as amount
                            ")
                    ),
                'raw_ledger'
            )
            ->selectRaw('
                ROW_NUMBER() OVER (ORDER BY date, source) as id,
                employee_id,
                user_id,
                leave_type_id,
                employee_name,
                date,
                source,
                amount
            ');
    }
}
