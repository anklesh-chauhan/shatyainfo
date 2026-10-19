<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class HrApprovalPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [];
        foreach (['HrChangeRequest' => ['ViewAny', 'View', 'Create'], 'LeaveCancellation' => ['ViewAny', 'View'], 'EmpSalary' => ['Update']] as $model => $actions) {
            foreach ($actions as $action) {
                $permissions[] = Permission::findOrCreate($action.':'.$model, 'web');
            }
        }
        foreach (['administration_admin', 'hr_admin', 'hr_user'] as $name) {
            $role = Role::where('name', $name)->where('guard_name', 'web')->first();
            if ($role) {
                $role->givePermissionTo(collect($permissions)->filter(fn ($permission): bool => $name !== 'hr_user' || $permission->name !== 'Update:EmpSalary')->all());
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
