<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class MyApprovalsPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (['web', 'tenant'] as $guard) {
            $permission = Permission::findOrCreate('View:MyApprovals', $guard);
            Role::query()->where('guard_name', $guard)->each(fn (Role $role) => $role->givePermissionTo($permission));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
