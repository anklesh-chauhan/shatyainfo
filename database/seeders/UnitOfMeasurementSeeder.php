<?php

namespace Database\Seeders;

use App\Models\UnitOfMeasurement;
use Illuminate\Database\Seeder;

class UnitOfMeasurementSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['name' => 'Tablet', 'abbreviation' => 'tab'],
            ['name' => 'Capsule', 'abbreviation' => 'cap'],
            ['name' => 'Strip', 'abbreviation' => 'strip'],
            ['name' => 'Bottle', 'abbreviation' => 'btl'],
            ['name' => 'Vial', 'abbreviation' => 'vial'],
            ['name' => 'Ampoule', 'abbreviation' => 'amp'],
            ['name' => 'Tube', 'abbreviation' => 'tube'],
            ['name' => 'Sachet', 'abbreviation' => 'sachet'],
            ['name' => 'Box', 'abbreviation' => 'box'],
            ['name' => 'Milliliter', 'abbreviation' => 'mL'],
            ['name' => 'Liter', 'abbreviation' => 'L'],
            ['name' => 'Milligram', 'abbreviation' => 'mg'],
            ['name' => 'Gram', 'abbreviation' => 'g'],
            ['name' => 'Kilogram', 'abbreviation' => 'kg'],
            ['name' => 'Piece', 'abbreviation' => 'pcs'],
        ] as $attributes) {
            UnitOfMeasurement::query()->firstOrCreate(['name' => $attributes['name']], $attributes);
        }
    }
}
