<?php

namespace Database\Seeders;

use App\Models\AccountMaster;
use App\Models\Category;
use App\Models\Deal;
use App\Models\ItemMaster;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $groups = [
            ItemMaster::class => ['Pharmaceutical Products' => ['Tablets', 'Capsules', 'Oral Liquids', 'Injectables', 'Topical Preparations', 'Eye / Ear Drops', 'Inhalation Products', 'Nutraceuticals'], 'Promotional Materials' => ['Product Literature', 'Visual Aids', 'Patient Education Materials']],
            AccountMaster::class => ['Healthcare Customers' => ['Doctors', 'Chemists / Pharmacies', 'Stockists / Distributors', 'Hospitals / Institutions']],
            Deal::class => ['Pharma Sales' => ['Stockist Orders', 'Institutional Supply', 'Pharmacy Orders']],
        ];
        foreach ($groups as $model => $roots) {
            foreach ($roots as $name => $children) {
                $root = Category::query()->firstOrCreate(['name' => $name, 'modelable_type' => $model, 'parent_id' => null], ['description' => $name]);
                foreach ($children as $child) {
                    Category::query()->firstOrCreate(['name' => $child, 'modelable_type' => $model, 'parent_id' => $root->id], ['description' => $child]);
                }
            }
        }
    }
}
