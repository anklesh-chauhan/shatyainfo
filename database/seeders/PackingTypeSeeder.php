<?php

namespace Database\Seeders;

use App\Models\PackingType;
use Illuminate\Database\Seeder;

class PackingTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['name' => 'Strip', 'description' => 'Pharmaceutical strip packaging'],
            ['name' => 'Blister Pack', 'description' => 'Pharmaceutical blister pack packaging'],
            ['name' => 'Bottle', 'description' => 'Pharmaceutical bottle packaging'],
            ['name' => 'Vial', 'description' => 'Pharmaceutical vial packaging'],
            ['name' => 'Ampoule', 'description' => 'Pharmaceutical ampoule packaging'],
            ['name' => 'Tube', 'description' => 'Pharmaceutical tube packaging'],
            ['name' => 'Sachet', 'description' => 'Pharmaceutical sachet packaging'],
            ['name' => 'Box', 'description' => 'Pharmaceutical box packaging'],
            ['name' => 'Carton', 'description' => 'Pharmaceutical carton packaging'],
        ] as $attributes) {
            PackingType::query()->firstOrCreate(['name' => $attributes['name']], $attributes);
        }
    }
}
