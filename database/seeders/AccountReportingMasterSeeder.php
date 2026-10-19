<?php

namespace Database\Seeders;

use App\Models\AccountReportingMaster;
use Illuminate\Database\Seeder;

class AccountReportingMasterSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'specialty' => ['general_medicine' => 'General Medicine', 'cardiology' => 'Cardiology', 'diabetology' => 'Diabetology', 'pediatrics' => 'Pediatrics', 'gynecology' => 'Gynecology', 'dermatology' => 'Dermatology', 'orthopedics' => 'Orthopedics', 'pulmonology' => 'Pulmonology'],
            'therapy_area' => ['cardiovascular' => 'Cardiovascular', 'diabetes' => 'Diabetes', 'respiratory' => 'Respiratory', 'anti_infectives' => 'Anti-infectives', 'gastrointestinal' => 'Gastrointestinal', 'pain_management' => 'Pain Management'],
            'potential_unit' => ['revenue' => 'Revenue', 'prescriptions' => 'Prescriptions', 'patients' => 'Patients', 'packs' => 'Packs'],
        ] as $kind => $values) {
            foreach ($values as $code => $name) {
                AccountReportingMaster::firstOrCreate(['code' => $kind.'_'.$code], ['kind' => $kind, 'name' => $name, 'is_monetary' => $code === 'revenue']);
            }
        }
    }
}
