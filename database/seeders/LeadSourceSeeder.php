<?php

namespace Database\Seeders;

use App\Models\LeadSource;
use Illuminate\Database\Seeder;

class LeadSourceSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['name' => 'Doctor Referral'],
            ['name' => 'Chemist Referral'],
            ['name' => 'Stockist Referral'],
            ['name' => 'Medical Conference / CME'],
            ['name' => 'Hospital Visit'],
            ['name' => 'Field Visit'],
            ['name' => 'Website Enquiry'],
            ['name' => 'Healthcare Camp'],
        ] as $attributes) {
            LeadSource::query()->firstOrCreate(['name' => $attributes['name']], $attributes);
        }
    }
}
