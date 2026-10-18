<?php

namespace Database\Seeders;

use App\Models\IndustryType;
use Illuminate\Database\Seeder;

class IndustryTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['name' => 'Pharmaceuticals'],
            ['name' => 'Healthcare / Hospitals'],
            ['name' => 'Retail Pharmacy'],
            ['name' => 'Pharmaceutical Distribution'],
            ['name' => 'Diagnostics'],
            ['name' => 'Nutraceuticals'],
        ] as $attributes) {
            IndustryType::query()->firstOrCreate(['name' => $attributes['name']], $attributes);
        }
    }
}
