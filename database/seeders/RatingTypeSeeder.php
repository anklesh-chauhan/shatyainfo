<?php

namespace Database\Seeders;

use App\Models\RatingType;
use Illuminate\Database\Seeder;

class RatingTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['A - High Potential', 'B - Medium Potential', 'C - Low Potential', 'Unclassified - Pending Assessment'] as $name) {
            RatingType::withTrashed()->firstOrCreate(['name' => $name]);
        }
    }
}
