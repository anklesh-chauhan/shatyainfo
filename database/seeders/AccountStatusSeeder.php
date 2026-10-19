<?php

namespace Database\Seeders;

use App\Models\AccountStatus;
use Illuminate\Database\Seeder;

class AccountStatusSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['prospect' => 'Prospect', 'active' => 'Active', 'inactive' => 'Inactive', 'closed' => 'Closed'] as $code => $name) {
            AccountStatus::withTrashed()->firstOrCreate(['code' => $code], ['name' => $name]);
        }
    }
}
