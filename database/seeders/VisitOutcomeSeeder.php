<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class VisitOutcomeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = Carbon::now();

        $outcomes = [
            [
                'label' => 'Order Taken',
                'code' => 'ORDER_TAKEN',
                'notes' => 'Customer placed an order during the visit',
            ],
            [
                'label' => 'Follow-up Required',
                'code' => 'FOLLOW_UP_REQUIRED',
                'notes' => 'Customer needs follow-up or additional discussion',
            ],
            [
                'label' => 'No Requirement',
                'code' => 'NO_REQUIREMENT',
                'notes' => 'Customer currently has no requirement',
            ],
            [
                'label' => 'Not Available',
                'code' => 'NOT_AVAILABLE',
                'notes' => 'Customer was not available at the time of visit',
            ],
            [
                'label' => 'Visit Cancelled',
                'code' => 'VISIT_CANCELLED',
                'notes' => 'Visit was cancelled or postponed',
            ],
            [
                'label' => 'Payment Collected',
                'code' => 'PAYMENT_COLLECTED',
                'notes' => 'Outstanding payment collected during visit',
            ],
            [
                'label' => 'Product Detailing Completed',
                'code' => 'DEMO_GIVEN',
                'notes' => 'Product information presented to the doctor or healthcare customer',
            ],
        ];

        foreach ($outcomes as $outcome) {
            DB::table('visit_outcomes')->updateOrInsert(
                ['code' => $outcome['code']], // prevent duplicates
                array_merge($outcome, [
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
            );
        }
    }
}
