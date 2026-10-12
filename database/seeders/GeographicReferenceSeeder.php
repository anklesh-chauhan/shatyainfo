<?php

namespace Database\Seeders;

use App\Support\Tenancy\GeographicReference;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GeographicReferenceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(GeographicReference::class)->copyToDatabase(
            (string) DB::connection()->getDatabaseName(),
        );
    }
}
