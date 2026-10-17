<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmploymentDetail;
use App\Models\Position;
use Illuminate\Validation\ValidationException;

class EmployeeReportingService
{
    public static bool $deferred = false;

    /** @return array{manager: ?int, status: string} */
    public function resolve(Employee $employee): array
    {
        $employee->loadMissing('positions.reportsTo.employees');
        $primary = $employee->positions->filter(fn ($position): bool => (bool) $position->pivot->is_primary);
        if ($primary->count() !== 1) {
            return ['manager' => null, 'status' => 'Select exactly one primary position'];
        }
        $parent = $primary->first()->reportsTo;
        if (! $parent) {
            return ['manager' => null, 'status' => 'Top-level position'];
        }
        $occupants = $parent->employees->filter(fn (Employee $person): bool => (bool) $person->is_active);
        if ($occupants->isEmpty()) {
            return ['manager' => null, 'status' => 'Reporting position vacant'];
        }
        if ($occupants->count() !== 1) {
            return ['manager' => null, 'status' => 'Multiple employees occupy the reporting position'];
        }
        $manager = $occupants->first();
        if ($manager->id === $employee->id) {
            return ['manager' => null, 'status' => 'An employee cannot report to themselves'];
        }

        return ['manager' => $manager->id, 'status' => 'Reports to '.$manager->full_name];
    }

    public function synchronize(): void
    {
        if (self::$deferred) {
            return;
        }
        Employee::query()->with('positions.reportsTo.employees')->chunkById(200, function ($employees): void {
            foreach ($employees as $employee) {
                $manager = $this->resolve($employee)['manager'];
                EmploymentDetail::query()->where('employee_id', $employee->id)
                    ->where(function ($query) use ($manager): void {
                        if ($manager === null) {
                            $query->whereNotNull('reporting_manager_id');
                        } else {
                            $query->whereNull('reporting_manager_id')->orWhere('reporting_manager_id', '!=', $manager);
                        }
                    })->update(['reporting_manager_id' => $manager]);
            }
        });
    }

    public function validatePosition(Position $position): void
    {
        $seen = [$position->id => true];
        $parent = $position->reports_to_position_id;
        while ($parent !== null) {
            if (isset($seen[$parent])) {
                throw ValidationException::withMessages(['reports_to_position_id' => 'A position cannot report to itself or create a circular reporting chain.']);
            }
            $seen[$parent] = true;
            $parent = Position::query()->whereKey($parent)->value('reports_to_position_id');
        }
    }
}
