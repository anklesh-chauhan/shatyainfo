<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\EmploymentDetail;
use App\Models\OrganizationalUnit;
use App\Models\Territory;
use App\Models\User;
use App\Services\OrganizationalUnitService;
use Faker\Factory as Faker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SalesUsersSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create('en_IN');
        $systemUserId = 1;

        /* ================= ORGANIZATIONAL UNITS ================= */
        $ho = OrganizationalUnit::where('code', 'HO')->first();
        $sales = OrganizationalUnit::where('code', 'SALES')->first();
        $salesN = OrganizationalUnit::where('code', 'SALES-N')->first();
        $salesS = OrganizationalUnit::where('code', 'SALES-S')->first();

        if (! $sales || ! $salesN || ! $salesS) {
            $this->command->error('Sales OUs not found. Run OrganizationalUnitSeeder first.');

            return;
        }

        /* ================= TERRITORIES ================= */
        $northTerritories = Territory::whereHas('divisions', fn ($q) => $q->where('organizational_units.id', $salesN->id)
        )->pluck('id')->toArray();

        $southTerritories = Territory::whereHas('divisions', fn ($q) => $q->where('organizational_units.id', $salesS->id)
        )->pluck('id')->toArray();

        /* ================= SALES EXECUTIVES ================= */
        $this->createSalesUsers(
            title: 'Sales Executive',
            role: 'sales_user',
            count: 6,
            ou: $salesN,
            territories: $northTerritories,
            faker: $faker,
            systemUserId: $systemUserId
        );

        $this->createSalesUsers(
            title: 'Sales Executive',
            role: 'sales_user',
            count: 6,
            ou: $salesS,
            territories: $southTerritories,
            faker: $faker,
            systemUserId: $systemUserId
        );

        /* ================= ASSISTANT SALES MANAGERS ================= */
        $this->createSalesUsers(
            title: 'Assistant Sales Manager',
            role: 'sales_user',
            count: 2,
            ou: $salesN,
            territories: array_merge($northTerritories, $southTerritories),
            faker: $faker,
            systemUserId: $systemUserId
        );

        /* ================= SALES OU HEAD ================= */
        $this->createSalesUsers(
            title: 'Sales OU Head',
            role: 'sales_admin',
            count: 1,
            ou: $sales,
            territories: array_merge($northTerritories, $southTerritories),
            faker: $faker,
            systemUserId: $systemUserId
        );

        /* ================= SALES HEAD ================= */
        $this->createSalesUsers(
            title: 'Sales Head',
            role: 'sales_admin',
            count: 1,
            ou: $ho,
            territories: Territory::pluck('id')->toArray(),
            faker: $faker,
            systemUserId: $systemUserId
        );

        $this->command->info('Sales users seeded successfully.');
    }

    /* ============================================================= */

    protected function createSalesUsers(
        string $title,
        string $role,
        int $count,
        OrganizationalUnit $ou,
        array $territories,
        $faker,
        int $systemUserId
    ): void {
        for ($i = 1; $i <= $count; $i++) {

            /* ---------------- USER ---------------- */
            $email = strtolower(str_replace(' ', '.', $title))."{$i}.{$ou->code}@company.com";

            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => "{$title} {$i}",
                    'password' => Hash::make('password'),
                    'created_by' => $systemUserId,
                    'updated_by' => $systemUserId,
                ]
            );

            if (! $user->hasRole($role)) {
                $user->assignRole($role);
            }

            /* ---------------- EMPLOYEE ---------------- */
            $employee = Employee::updateOrCreate(
                ['login_id' => $user->id],
                [
                    'employee_id' => $user->id,
                    'first_name' => "{$title}",
                    'last_name' => "{$i}",
                    'email' => $user->email,
                    'mobile_number' => $faker->numerify('9#########'),
                    'is_active' => true,
                    'created_by' => $systemUserId,
                    'updated_by' => $systemUserId,
                ]
            );

            /* ---------------- EMPLOYMENT DETAIL ---------------- */
            $divisionId = OrganizationalUnitService::divisionId($ou->id);

            $employment = EmploymentDetail::updateOrCreate(
                ['employee_id' => $employee->id],
                [
                    'division_ou_id' => $divisionId,
                    'hire_date' => now()->subYears(rand(1, 8)),
                    'employment_type' => 'Permanent',
                    'employment_status' => 'Active',
                    'created_by' => $systemUserId,
                    'updated_by' => $systemUserId,
                ]
            );

            // 🔹 Primary OU assignment
            $employment->organizationalUnits()->syncWithoutDetaching([
                $ou->id => [
                    'is_primary' => true,
                    'effective_from' => now()->subYears(3),
                ],
            ]);

            // 🔹 Territory assignment (via OU → Territory mapping)
            if (! empty($territories)) {
                Territory::whereIn('id', $territories)->each(function ($territory) use ($ou) {
                    $territory->divisions()->syncWithoutDetaching([$ou->id]);
                });
            }
        }
    }
}
