<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class ItemCategorySeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CategorySeeder::class);
    }
}
