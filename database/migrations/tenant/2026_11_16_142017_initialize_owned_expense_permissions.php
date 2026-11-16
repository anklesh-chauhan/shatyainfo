<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Permission::query()->getConnection()->transaction(function (): void {
            $permissions = collect(['ViewAny', 'View', 'Create', 'Update', 'Delete'])
                ->map(fn (string $action): Permission => Permission::findOrCreate($action.':SalesDcrExpense', 'web'));
            $roles = Role::query()->where('guard_name', 'web')
                ->whereHas('permissions', fn ($permissions) => $permissions->where('name', 'Update:SalesDcr'))
                ->get();

            foreach ($roles as $role) {
                $role->givePermissionTo($permissions);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Keep current role assignments when rolling back application code.
    }
};
