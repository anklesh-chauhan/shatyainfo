<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

class OrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::current();
        if (! $tenant) {
            return;
        }

        $owner = User::query()->orderBy('id')->first();

        Organization::query()->firstOrCreate(['name' => $tenant->name], [
            'email' => $owner?->email,
            'status' => 'active',
            'timezone' => 'Asia/Kolkata',
            'language' => 'en',
            'metadata' => ['company_setup' => ['pending' => true]],
            'created_by' => $owner?->id,
            'updated_by' => $owner?->id,
        ]);
    }
}
