<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class DepartmentRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (['super_admin', 'sales_admin'] as $roleName) {

            Role::firstOrCreate(
                [
                    'name' => $roleName,
                    'guard_name' => 'web',
                ]
            );
        }
    }
}
