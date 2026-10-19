<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\HrChangeRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/** @extends Factory<HrChangeRequest> */
class HrChangeRequestFactory extends Factory
{
    protected $model = HrChangeRequest::class;

    public function definition(): array
    {
        return ['reason' => fake()->sentence()];
    }

    public function forChange(Model $target, string $type, array $values): static
    {
        return $this->state([
            'employee_id' => $target instanceof Employee ? $target->id : $target->employee_id,
            'request_type' => $type, 'target_type' => $target::class, 'target_id' => $target->id,
            'previous_values' => array_intersect_key($target->getRawOriginal(), $values), 'proposed_values' => $values,
        ]);
    }
}
