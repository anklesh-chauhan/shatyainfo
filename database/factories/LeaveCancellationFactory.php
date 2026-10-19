<?php

namespace Database\Factories;

use App\Models\LeaveApplication;
use App\Models\LeaveCancellation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LeaveCancellation> */
class LeaveCancellationFactory extends Factory
{
    protected $model = LeaveCancellation::class;

    public function definition(): array
    {
        return ['reason' => fake()->sentence()];
    }

    public function forLeave(LeaveApplication $leave): static
    {
        return $this->state(['leave_application_id' => $leave->id, 'employee_id' => $leave->employee_id]);
    }
}
