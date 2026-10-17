<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ConfigDrivenShieldPermissionSeeder::class,
            GeographicReferenceSeeder::class,
            IndustryTypeSeeder::class,
            LeadSourceSeeder::class,
            LeadStatusSeeder::class,
            RatingTypeSeeder::class,
            FollowUpMediaSeeder::class,
            FollowUpResultSeeder::class,
            FollowUpStatusSeeder::class,
            FollowUpPrioritySeeder::class,
            UnitOfMeasurementSeeder::class,
            PackingTypeSeeder::class,
            DealStageSeeder::class,
            TypeMasterSeeder::class,
            CategorySeeder::class,
            DesignationSeeder::class,
            DepartmentSeeder::class,
            RegionSeeder::class,
            TaxSeeder::class,
            TaxComponentSeeder::class,
            SalesDocumentPreferenceSeeder::class,
            PaymentTermsSeeder::class,
            PaymentMethodsSeeder::class,
            ShippingMethodsSeeder::class,
            AddressTypeSeeder::class,
            EmployeeAttendanceStatusSeeder::class,
            DepartmentRoleSeeder::class,
            LeaveRuleCategorySeeder::class,
            LeaveRuleSeeder::class,
            HolidaySeeder::class,
            ShiftMasterSeeder::class,
            ExpenseTypeSeeder::class,
            TransportModeSeeder::class,
            VisitTypeAndPurposeSeeder::class,
            VisitOutcomeSeeder::class,
            VisitFeedbackQuestionSeeder::class,
            VisitPreferenceSeeder::class,
            ExpensePolicySeeder::class,
            ImageTagSeeder::class,

        ]);
    }
}
