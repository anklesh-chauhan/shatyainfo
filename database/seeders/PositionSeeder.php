<?php

namespace Database\Seeders;

use App\Enums\PositionStatus;
use App\Models\JobRole;
use App\Models\OrganizationalUnit;
use App\Models\Position;
use App\Models\Territory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PositionSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {

            /* ================= LOAD BASE DATA ================= */
            $roles = JobRole::all()->keyBy('code');
            $ous = OrganizationalUnit::all()->keyBy('code');
            $territories = Territory::all();

            if ($roles->isEmpty() || $ous->isEmpty() || $territories->isEmpty()) {
                $this->command?->warn('Missing JobRoles / OUs / Territories');

                return;
            }

            /* ================= CEO ================= */
            $ceo = $this->create([
                'name' => 'Chief Executive Officer',
                'code' => 'POS-CEO',
                'job_role_id' => $roles['CEO']->id,
                'division_ou_id' => null,
                'organizational_unit_id' => $ous['HO']->id,
                'level' => 1,
            ]);

            /* ================= HEAD OF SALES ================= */
            $headSales = $this->create([
                'name' => 'Head of Sales',
                'code' => 'POS-H-SALES',
                'job_role_id' => $roles['H-SALES']->id,
                'division_ou_id' => $ous['SALES']->id,
                'organizational_unit_id' => $ous['SALES']->id,
                'reports_to_position_id' => $ceo->id,
                'level' => 2,
                'is_multi_territory' => true,
            ]);

            // 🔗 Attach ALL territories to Head of Sales
            $headSales->territories()->sync($territories->pluck('id'));

            /* ================= SALES MANAGERS & EXECUTIVES ================= */
            foreach ($territories as $territory) {

                /* ===== Sales Manager (per territory) ===== */
                $salesManager = $this->create([
                    'name' => "Sales Manager – {$territory->name}",
                    'code' => "POS-SM-{$territory->code}",
                    'job_role_id' => $roles['SALES-MGR']->id,
                    'division_ou_id' => $ous['SALES']->id,
                    'organizational_unit_id' => $ous['SALES']->id,
                    'reports_to_position_id' => $headSales->id,
                    'level' => 3,
                    'is_multi_territory' => false,
                ]);

                // 🔗 Map territory
                $salesManager->territories()->sync([$territory->id]);

                /* ===== Sales Executive (per territory) ===== */
                $salesExecutive = $this->create([
                    'name' => "Sales Executive – {$territory->name}",
                    'code' => "POS-SE-{$territory->code}",
                    'job_role_id' => $roles['SALES-EXEC']->id,
                    'division_ou_id' => $ous['SALES']->id,
                    'organizational_unit_id' => $ous['SALES']->id,
                    'reports_to_position_id' => $salesManager->id,
                    'level' => 4,
                    'is_multi_territory' => false,
                ]);

                // 🔗 Map territory
                $salesExecutive->territories()->sync([$territory->id]);
            }
        });
    }

    /* ================= HELPER ================= */
    protected function create(array $data): Position
    {
        return Position::create(array_merge([
            'status' => PositionStatus::Active,
            'approval_status' => 'approved',
            'description' => $data['name'],
        ], $data));
    }
}
