<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class PharmaMasterSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            IndustryTypeSeeder::class,
            TypeMasterSeeder::class,
            CategorySeeder::class,
            LeadSourceSeeder::class,
            UnitOfMeasurementSeeder::class,
            PackingTypeSeeder::class,
            VisitTypeAndPurposeSeeder::class,
            VisitOutcomeSeeder::class,
            ImageTagSeeder::class,
        ]);
    }
}
