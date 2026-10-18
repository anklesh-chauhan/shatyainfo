<?php

namespace Database\Seeders;

use App\Models\AccountMaster;
use App\Models\Address;
use App\Models\Deal;
use App\Models\LocationMaster;
use App\Models\OrganizationalUnit;
use App\Models\StandardFareChart;
use App\Models\TypeMaster;
use Illuminate\Database\Seeder;

class TypeMasterSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            AccountMaster::class => [
                'Customer' => ['Doctor', 'Chemist / Pharmacy', 'Stockist', 'Super Stockist', 'Hospital', 'Clinic', 'Nursing Home', 'Institution / Government Hospital'],
                'Vendor' => ['Manufacturer Vendor', 'Trader Vendor', 'Service Vendor'],
                'Distributor' => ['Pharmaceutical Distributor', 'C&F Agent'],
                'Transporter' => ['Fleet Transporter', 'Individual Transporter'],
            ],
            Address::class => ['Office Address' => ['Registered Office', 'Head Office']],
            Deal::class => ['Recurring Revenue' => ['Institutional Supply', 'Stockist Supply']],
            LocationMaster::class => ['Branch' => ['Regional Branch'], 'Warehouse' => ['Cold Storage Warehouse']],
            OrganizationalUnit::class => ['Organizational Unit' => ['Head Office', 'Division', 'Plant', 'Department']],
            StandardFareChart::class => ['Local / HQ' => [], 'Intra-District / Ex-Station / Ex-HQ' => [], 'Out-Station' => []],
        ];

        foreach ($types as $model => $roots) {
            foreach ($roots as $name => $children) {
                $root = TypeMaster::query()->firstOrCreate([
                    'name' => $name,
                    'typeable_type' => $model,
                    'parent_id' => null,
                ], ['description' => $name]);

                foreach ($children as $child) {
                    TypeMaster::query()->firstOrCreate([
                        'name' => $child,
                        'typeable_type' => $model,
                        'parent_id' => $root->id,
                    ], ['description' => $child]);
                }
            }
        }
    }
}
