<?php

namespace Database\Seeders;

use App\Models\AccountMaster;
use App\Models\IndustryType;
use App\Models\RatingType;
use App\Models\TypeMaster;
use Illuminate\Database\Seeder;

class PharmaAccountReferenceSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'Customer' => ['Doctor', 'Chemist / Pharmacy', 'Stockist', 'Super Stockist', 'Hospital', 'Clinic', 'Nursing Home', 'Institution / Government Hospital'],
            'Distributor' => ['Pharmaceutical Distributor', 'C&F Agent'],
        ] as $name => $children) {
            $root = TypeMaster::withTrashed()->firstOrCreate([
                'name' => $name,
                'typeable_type' => AccountMaster::class,
                'parent_id' => null,
            ], ['description' => $name]);

            foreach ($children as $child) {
                TypeMaster::withTrashed()->firstOrCreate([
                    'name' => $child,
                    'typeable_type' => AccountMaster::class,
                    'parent_id' => $root->id,
                ], ['description' => $child]);
            }
        }

        foreach (['A - High Potential', 'B - Medium Potential', 'C - Low Potential', 'Unclassified - Pending Assessment'] as $name) {
            RatingType::withTrashed()->firstOrCreate(['name' => $name]);
        }

        foreach (['Pharmaceuticals', 'Healthcare / Hospitals', 'Retail Pharmacy', 'Pharmaceutical Distribution', 'Diagnostics', 'Nutraceuticals'] as $name) {
            IndustryType::withTrashed()->firstOrCreate(['name' => $name]);
        }
    }
}
