<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmploymentDetail;
use App\Models\Position;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class EmployeeReportingService
{
    public static bool $deferred = false;

    /** @return array{manager: ?int, status: string, source?: string} */
    public function resolve(Employee $employee, ?Collection $employees = null): array
    {
        $resolution = $this->directManager($employee);
        $seen = [$employee->id => true];
        $current = $resolution;

        while ($current['manager'] !== null) {
            $id = $current['manager'];
            if (isset($seen[$id])) {
                return ['manager' => null, 'status' => 'Circular employee reporting chain or self-reporting detected'];
            }
            $seen[$id] = true;
            $manager = $employees === null ? Employee::query()->find($id) : $employees->get($id);
            if (! $manager) {
                break;
            }
            $current = $this->directManager($manager);
        }

        return $resolution;
    }

    /** @return array{manager: ?int, status: string, source?: string} */
    private function directManager(Employee $employee): array
    {
        if ($employee->explicit_reporting_manager_id !== null) {
            $employee->loadMissing('explicitReportingManager');
            $manager = $employee->explicitReportingManager;

            return $manager?->is_active && ! $manager->is_deleted
                ? ['manager' => $manager->id, 'status' => 'Reports to '.$manager->full_name, 'source' => 'Explicit reporting employee code']
                : ['manager' => null, 'status' => 'The explicit reporting employee is missing or inactive'];
        }

        $employee->loadMissing('positions.reportsTo.employees');
        $primary = $employee->positions->filter(fn ($position): bool => (bool) $position->pivot->is_primary);
        if ($primary->isEmpty()) {
            return ['manager' => null, 'status' => 'No reporting manager configured. Select a reporting employee or configure a primary position'];
        }
        if ($primary->count() !== 1) {
            return ['manager' => null, 'status' => 'Select exactly one primary position'];
        }
        $parent = $primary->first()->reportsTo;
        if (! $parent) {
            return ['manager' => null, 'status' => 'No reporting manager configured for the primary position'];
        }
        $occupants = $parent->employees->filter(fn (Employee $person): bool => $person->is_active && ! $person->is_deleted);
        if ($occupants->isEmpty()) {
            return ['manager' => null, 'status' => 'Reporting position '.$parent->code.' is vacant'];
        }
        if ($occupants->count() !== 1) {
            return ['manager' => null, 'status' => 'Multiple employees occupy the reporting position'];
        }
        $manager = $occupants->first();

        return ['manager' => $manager->id, 'status' => 'Reports to '.$manager->full_name, 'source' => 'Active occupant of reporting position '.$parent->code];
    }

    public function synchronize(): void
    {
        if (self::$deferred) {
            return;
        }
        Employee::query()->with('positions.reportsTo.employees', 'explicitReportingManager')->chunkById(200, function ($employees): void {
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
