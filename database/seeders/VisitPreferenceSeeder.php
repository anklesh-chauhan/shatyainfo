<?php

namespace Database\Seeders;

use App\Models\VisitPreference;
use Illuminate\Database\Seeder;

class VisitPreferenceSeeder extends Seeder
{
    public function run(): void
    {
        VisitPreference::query()->firstOrCreate(
            [], // singleton key
            [
                /* ===============================
                 | Visit Flow Controls
                 =============================== */
                'enable_check_in' => true,
                'enable_check_out' => true,
                'enforce_check_in_before_check_out' => true,
                'allow_manual_time_edit' => false,

                /* ===============================
                 | Proof & Compliance
                 =============================== */
                'require_check_in_image' => false,
                'require_check_out_image' => false,
                'require_general_visit_image' => false,
                'require_gps' => true,
                'geo_fence_radius_meters' => 200,

                /* ===============================
                 | Visit Duration Rules
                 =============================== */
                'enforce_minimum_duration' => false,
                'minimum_duration_minutes' => null,

                /* ===============================
                 | Dynamic Field Rules
                 =============================== */
                'field_rules' => [
                    'purpose' => [
                        'visible' => true,
                        'required' => true,
                        'editable' => true,
                    ],
                    'outcome' => [
                        'visible' => true,
                        'required' => false,
                        'editable' => true,
                    ],
                    'notes' => [
                        'visible' => true,
                        'required' => false,
                        'editable' => true,
                    ],
                    'next_follow_up_date' => [
                        'visible' => true,
                        'required' => false,
                        'editable' => true,
                    ],
                    'competitor_info' => [
                        'visible' => false,
                        'required' => false,
                        'editable' => false,
                    ],
                ],

                /* ===============================
                 | Other Preferences
                 =============================== */
                'allow_rescheduling' => true,
                'allow_cancellation' => true,
                'require_visit_outcome' => false,
                'allow_backdated_dcr' => true,
                'dcr_backdate_days' => 3,
            ]
        );
    }
}
