<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class SfaPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (['Create:SecondarySales', 'Review:SecondarySales', 'Manage:SkuUnitConversion', 'Manage:SecondarySalesPreference', 'Capture:Rcpa', 'View:Rcpa', 'Manage:RcpaCompetitor', 'View:SalesTargets', 'Manage:SalesTargets', 'Review:FieldSyncRecovery'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
