<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            'View:Dashboard' => ['ViewAny:Visit', 'ViewAny:DailyAttendance'],
            'View:TodaysTour' => ['ViewAny:SalesTourPlan', 'ViewAny:Visit'],
            'View:SalesAssistant' => ['ViewAny:SalesTourPlan', 'ViewAny:Visit'],
            'View:MyApprovals' => ['ViewAny:Approval', 'Approve:Approval'],
            'View:PunchIn' => ['Create:Visit', 'Create:DailyAttendance'],
            'Require:PunchIn' => [],
            'View:DcrRegister' => ['ViewAny:SalesDcr'],
            'View:VisitCoverage' => ['ViewAny:SalesTourPlanDetail'],
            'View:ExpenseSummary' => ['ViewAny:SalesDcrExpense'],
            'View:DoctorSampleLedger' => ['ViewAny:SgipDistribution'],
            'View:SgipCompliance' => ['ViewAny:SgipDistribution'],
            'View:StockMovementRegister' => ['ViewAny:InventoryMovement'],
            'View:LeadPipeline' => ['ViewAny:Lead'],
            'View:DealPipeline' => ['ViewAny:Deal'],
            'View:SalesDocumentRegister' => ['ViewAny:Quote', 'ViewAny:SalesOrder', 'ViewAny:SalesInvoice'],
            'View:OverdueFollowUps' => ['ViewAny:FollowUp'],
            'View:AttendanceRegister' => ['ViewAny:DailyAttendance'],
            'View:PunchVsTour' => ['ViewAny:DailyAttendance'],
            'View:LeaveLedger' => ['ViewAny:LeaveBalance'],
            'View:ReportsOverview' => ['ViewAny:AccountMaster', 'View:DcrRegister', 'View:VisitCoverage', 'View:ExpenseSummary', 'View:DoctorSampleLedger', 'View:SgipCompliance', 'View:StockMovementRegister', 'View:LeadPipeline', 'View:DealPipeline', 'View:SalesDocumentRegister', 'View:OverdueFollowUps', 'View:AttendanceRegister', 'View:PunchVsTour', 'View:LeaveLedger'],
            'Export:Reports' => ['View:ReportsOverview', 'ViewAny:AccountMaster', 'View:DcrRegister', 'View:VisitCoverage', 'View:ExpenseSummary', 'View:DoctorSampleLedger', 'View:SgipCompliance', 'View:StockMovementRegister', 'View:LeadPipeline', 'View:DealPipeline', 'View:SalesDocumentRegister', 'View:OverdueFollowUps', 'View:AttendanceRegister', 'View:PunchVsTour', 'View:LeaveLedger'],
        ];

        Permission::query()->getConnection()->transaction(function () use ($permissions): void {
            foreach ($permissions as $name => $existingPermissions) {
                $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);

                if (! $permission->wasRecentlyCreated) {
                    continue;
                }

                $roles = Role::query()
                    ->where('guard_name', 'web')
                    ->where(function ($query) use ($name, $existingPermissions): void {
                        $query->whereHas('permissions', fn ($permissions) => $permissions->whereIn('name', $existingPermissions));

                        if (in_array($name, ['View:Dashboard', 'View:TodaysTour', 'View:SalesAssistant', 'View:PunchIn', 'Require:PunchIn'], true)) {
                            $query->orWhere('name', 'marketing');
                        }
                    })
                    ->get();

                foreach ($roles as $role) {
                    $role->givePermissionTo($permission);

                    if ($name === 'Require:PunchIn') {
                        $role->givePermissionTo('View:PunchIn');
                    }
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Retain role assignments made by administrators after the migration.
    }
};
