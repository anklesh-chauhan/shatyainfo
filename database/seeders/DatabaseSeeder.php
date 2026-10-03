<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // // Disable foreign key checks to avoid constraint issues
        // DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        // // Truncate all tables you want to reset
        // DB::table('model_has_roles')->truncate();

        // // Enable foreign key checks again
        // DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        // try {
        //     Artisan::call('shield:generate --all');
        //     $this->command->info('Filament Shield permissions generated successfully.');
        // } catch (\Exception $e) {
        //     $this->command->error('Failed to run shield:generate --all: ' . $e->getMessage());
        // }

        $this->call([
            UsersTableSeeder::class,
            ModelHasRolesSeeder::class,
            ConfigDrivenShieldPermissionSeeder::class,
            CountrySeeder::class,
            StateSeeder::class,
            CitySeeder::class,
            CityPinCodeSeeder::class,
            OrganizationalUnitSeeder::class,
            IndustryTypeSeeder::class,
            LeadSourceSeeder::class,
            LeadStatusSeeder::class,
            RatingTypeSeeder::class,
            FollowUpMediaSeeder::class,
            FollowUpResultSeeder::class,
            FollowUpStatusSeeder::class,
            FollowUpPrioritySeeder::class,
            UnitOfMeasurementSeeder::class,
            ItemBrandSeeder::class,
            PackingTypeSeeder::class,
            DealStageSeeder::class,
            TypeMasterSeeder::class,
            CategorySeeder::class,
            DesignationSeeder::class,
            DepartmentSeeder::class,
            EmpDepartmentSeeder::class,
            EmpGradeSeeder::class,
            EmpJobTitleSeeder::class,
            RegionSeeder::class,
            AccountTypeSeeder::class,
            ChartOfAccountSeeder::class,
            OrganizationSeeder::class,
            TaxSeeder::class,
            TaxComponentSeeder::class,
            SalesDocumentPreferenceSeeder::class,
            PaymentTermsSeeder::class,
            PaymentMethodsSeeder::class,
            ShippingMethodsSeeder::class,
            AddressTypeSeeder::class,
            EmployeeAttendanceStatusSeeder::class,
            TerritorySeeder::class,
            AccountMasterSeeder::class,
            LocationMasterSeeder::class,
            ItemCategorySeeder::class,
            ItemMasterSeeder::class,
            SalesUsersSeeder::class,
            DepartmentRoleSeeder::class,
            LeaveRuleCategorySeeder::class,
            LeaveRuleSeeder::class,
            LeaveDemoDataSeeder::class,
            HolidaySeeder::class,
            ShiftMasterSeeder::class,
            JobRoleSeeder::class,
            PositionSeeder::class,
            PositionEmployeeMapperSeeder::class,
            ExpenseTypeSeeder::class,
            TransportModeSeeder::class,
            VisitTypeAndPurposeSeeder::class,
            VisitOutcomeSeeder::class,
            VisitFeedbackQuestionSeeder::class,
            VisitPreferenceSeeder::class,
            ExpensePolicySeeder::class,
            ImageTagSeeder::class,
            PatchSeeder::class,
            StandardFareChartSeeder::class,
            FullDemoSeeder::class,
        ]);
    }
}

class ModelHasRolesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $roleAssignments = [
            [
                'role_id' => 1, // Super-admin role (e.g., from filament-shield)
                'model_type' => 'App\\Models\\User',
                'model_id' => 1, // Admin user
            ],
        ];

        // Insert role assignments into the model_has_roles table
        DB::table('model_has_roles')->insert($roleAssignments);
    }
}
