<?php

namespace App\Services\DataImport;

use App\Models\Employee;
use App\Models\Position;
use App\Services\EmployeeReportingService;
use App\Services\OrganizationalUnitService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class EmployeeHierarchyImport
{
    /** @param array<int, array<string, mixed>> $rows */
    public function import(array $rows): ImportResult
    {
        if ($rows === []) {
            return new ImportResult;
        }
        $line = array_key_first($rows);
        $previous = EmployeeReportingService::$deferred;
        EmployeeReportingService::$deferred = true;
        try {
            return DB::transaction(function () use ($rows, &$line): ImportResult {
                $importer = app(EmployeeOnboardingImporter::class);
                $service = app(EmployeeReportingService::class);
                $result = new ImportResult;
                $employees = [];
                $employeeRows = [];
                $seenValues = [];
                foreach ($rows as $line => $row) {
                    foreach (['is_primary_position' => ['yes', 'no'], 'reporting_action' => ['set', 'clear'], 'reporting_employee_action' => ['clear'], 'assignment_action' => ['assign', 'remove']] as $field => $allowed) {
                        if (filled($row[$field] ?? null) && ! in_array(strtolower($row[$field]), $allowed, true)) {
                            throw new ImportRowException($field.' must be '.implode(' or ', $allowed).'.');
                        }
                    }
                    $code = strtolower($row['employee_id'] ?? '');
                    foreach (['first_name', 'last_name', 'mobile_number', 'email', 'temporary_password', 'hq_code'] as $field) {
                        if (filled($row[$field] ?? null)) {
                            if (isset($seenValues[$code][$field]) && $seenValues[$code][$field] !== $row[$field]) {
                                throw new ImportRowException('Conflicting '.$field.' for employee '.$row['employee_id'].'.');
                            }
                            $seenValues[$code][$field] = $row[$field];
                        }
                    }
                    $outcome = $importer->importRow($row, true);
                    if (! isset($employees[$code])) {
                        $result->{$outcome}++;
                    }
                    $employees[$code] = Employee::query()->where('employee_id', $row['employee_id'])->firstOrFail();
                    $employeeRows[$code][$line] = $row;
                }

                $positions = Position::query()->lockForUpdate()->get()->keyBy('id');
                $byCode = $positions->keyBy(fn (Position $position): string => strtolower($position->code));
                $graph = $positions->map(fn (Position $position): ?int => $position->reports_to_position_id)->all();
                $changes = [];
                $changeRows = [];
                $changedPositionIds = [];
                foreach ($rows as $line => $row) {
                    $position = $byCode->get(strtolower($row['position_code'] ?? ''));
                    $targetCode = trim($row['reports_to_position_code'] ?? '');
                    $action = strtolower($row['reporting_action'] ?? '');
                    if ($targetCode === '' && $action === '') {
                        continue;
                    }
                    if (! $position) {
                        throw new ImportRowException('Reporting changes require a position_code.');
                    }
                    if (($action === 'clear' && $targetCode !== '') || ($action === 'set' && $targetCode === '')) {
                        throw new ImportRowException('Use clear with a blank reporting position, or set with a reporting position code.');
                    }
                    $parent = $targetCode !== '' ? $byCode->get(strtolower($targetCode)) : null;
                    if ($targetCode !== '' && ! $parent) {
                        throw new ImportRowException('Reporting position '.$targetCode.' does not exist in this file or company.');
                    }
                    $parentId = $parent?->id;
                    if (array_key_exists($position->id, $changes) && $changes[$position->id] !== $parentId) {
                        throw new ImportRowException('Conflicting reporting positions for '.$position->code.'.');
                    }
                    $changes[$position->id] = $parentId;
                    $changeRows[$position->id] = $line;
                    $graph[$position->id] = $parentId;
                }
                foreach ($changes as $id => $parentId) {
                    $line = $changeRows[$id];
                    $this->assertAcyclic($graph, $id);
                }
                foreach ($changes as $id => $parentId) {
                    $position = $positions[$id];
                    if ($position->reports_to_position_id !== $parentId) {
                        $importer->authorize('update', $position);
                        $position->reports_to_position_id = null;
                        $position->save();
                        $changedPositionIds[] = $id;
                    }
                }
                foreach ($changes as $id => $parentId) {
                    $position = $positions[$id];
                    if ($position->reports_to_position_id !== $parentId) {
                        $position->reports_to_position_id = $parentId;
                        $position->save();
                    }
                }

                foreach ($employees as $code => $employee) {
                    $assignments = $employee->positions()->get()->mapWithKeys(fn ($position): array => [$position->id => ['is_primary' => (bool) $position->pivot->is_primary]])->all();
                    $explicitPrimary = null;
                    $assignmentClaims = [];
                    foreach ($employeeRows[$code] as $line => $row) {
                        $positionCode = trim($row['position_code'] ?? '');
                        if ($positionCode === '') {
                            if (filled($row['is_primary_position'] ?? null) || filled($row['assignment_action'] ?? null)) {
                                throw new ImportRowException('Assignment changes require position_code.');
                            }

                            continue;
                        }
                        $position = $byCode[strtolower($positionCode)];
                        $changedPositionIds[] = $position->id;
                        $remove = strtolower($row['assignment_action'] ?? '') === 'remove';
                        $primary = strtolower($row['is_primary_position'] ?? '');
                        $claim = [$remove, $primary];
                        if (isset($assignmentClaims[$position->id]) && $assignmentClaims[$position->id] !== $claim) {
                            throw new ImportRowException('Conflicting assignment rows for '.$employee->employee_id.' / '.$positionCode.'.');
                        }
                        $assignmentClaims[$position->id] = $claim;
                        if ($remove) {
                            if ($primary === 'yes') {
                                throw new ImportRowException('A removed assignment cannot be primary.');
                            }
                            unset($assignments[$position->id]);

                            continue;
                        }
                        $assignments[$position->id] ??= ['is_primary' => false];
                        if ($primary === 'yes') {
                            if ($explicitPrimary !== null && $explicitPrimary !== $position->id) {
                                throw new ImportRowException('Specify only one primary position for '.$employee->employee_id.'.');
                            }
                            $explicitPrimary = $position->id;
                        } elseif ($primary === 'no') {
                            $assignments[$position->id]['is_primary'] = false;
                        }
                    }
                    if ($explicitPrimary !== null) {
                        foreach ($assignments as $id => &$assignment) {
                            $assignment['is_primary'] = $id === $explicitPrimary;
                        }
                        unset($assignment);
                    }
                    if ($assignments !== [] && count(array_filter($assignments, fn (array $assignment): bool => $assignment['is_primary'])) !== 1) {
                        throw new ImportRowException('Employee '.$employee->employee_id.' must have exactly one primary position. Enter Yes in is_primary_position.');
                    }
                    $employee->positions()->sync($assignments);
                    foreach (array_keys($assignments) as $positionId) {
                        $assignedPosition = $positions[$positionId];
                        if ($assignedPosition->organizational_unit_id) {
                            $assignedPosition->organizationalUnits()->syncWithoutDetaching([$assignedPosition->organizational_unit_id]);
                        }
                    }
                    $employee->unsetRelation('positions');
                    $primaryPosition = $employee->positions()->wherePivot('is_primary', true)->first();
                    $employment = array_filter(['department_id' => $primaryPosition?->department_id, 'grade_id' => $primaryPosition?->job_grade_id, 'job_title_id' => $primaryPosition?->job_title_id, 'organizational_unit_id' => $primaryPosition?->organizational_unit_id], fn ($value): bool => $value !== null);
                    if ($primaryPosition?->organizational_unit_id) {
                        $employment['division_ou_id'] = OrganizationalUnitService::divisionId($primaryPosition?->organizational_unit_id);
                    }
                    foreach ($employeeRows[$code] as $line => $row) {
                        if (strtolower($row['position_code'] ?? '') !== strtolower($primaryPosition?->code ?? '') && filled($row['position_code'] ?? null)) {
                            continue;
                        }
                        foreach (['hire_date', 'employment_type'] as $field) {
                            if (filled($row[$field] ?? null)) {
                                $employment[$field] = $row[$field];
                            }
                        }
                    }
                    $detail = $employee->employmentDetail()->updateOrCreate([], $employment);
                    if ($primaryPosition?->organizational_unit_id) {
                        $detail->organizationalUnits()->syncWithoutDetaching([$primaryPosition?->organizational_unit_id]);
                    }
                }

                $allEmployees = Employee::query()->with('positions.reportsTo.employees', 'explicitReportingManager')->get()->keyBy('id');
                $byEmployeeCode = $allEmployees->groupBy(fn (Employee $employee): string => strtolower($employee->employee_id));
                foreach ($employees as $code => $employee) {
                    $claims = [];
                    foreach ($employeeRows[$code] as $line => $row) {
                        $managerCode = strtolower(trim($row['reports_to_employee_code'] ?? ''));
                        $clear = strtolower($row['reporting_employee_action'] ?? '') === 'clear';
                        if ($clear && $managerCode !== '') {
                            throw new ImportRowException('Leave reports_to_employee_code blank when clearing the reporting employee.');
                        }
                        if ($clear) {
                            $claims[] = null;
                        } elseif ($managerCode !== '') {
                            $matches = $byEmployeeCode->get($managerCode);
                            if ($matches?->count() > 1) {
                                throw new ImportRowException('Multiple employees match reporting employee code '.$managerCode.'.');
                            }
                            $manager = $matches?->first();
                            if (! $manager?->is_active || $manager->is_deleted) {
                                throw new ImportRowException('Reporting employee '.$managerCode.' does not exist or is inactive in this company.');
                            }
                            $claims[] = $manager->id;
                        }
                    }
                    $claims = array_values(array_unique($claims));
                    if (count($claims) > 1) {
                        throw new ImportRowException('Conflicting reporting employees for '.$employee->employee_id.'.');
                    }
                    if ($claims !== []) {
                        $record = $allEmployees[$employee->id];
                        $record->provisionLoginOnSave = false;
                        $record->update(['explicit_reporting_manager_id' => $claims[0]]);
                        $record->unsetRelation('explicitReportingManager');
                    }
                }

                foreach ($allEmployees as $employee) {
                    $code = strtolower($employee->employee_id);
                    $affected = isset($employees[$code]) || $employee->positions->contains(fn ($position): bool => in_array($position->reports_to_position_id, $changedPositionIds, true) || in_array($position->id, $changedPositionIds, true));
                    if (! $affected) {
                        continue;
                    }
                    $line = array_key_first($employeeRows[$code] ?? $rows);
                    $resolution = $service->resolve($employee, $allEmployees);
                    if ($resolution['manager'] === null && ($employee->explicit_reporting_manager_id !== null
                        || str_contains($resolution['status'], 'Circular') || str_contains($resolution['status'], 'Multiple employees'))) {
                        throw new ImportRowException($employee->employee_id.': '.$resolution['status'].'.');
                    }
                    if (isset($employees[$code])) {
                        $primary = $employee->positions->first(fn ($position): bool => (bool) $position->pivot->is_primary);
                        $manager = $allEmployees->get($resolution['manager'] ?? 0);
                        $result->hierarchy[] = ['employee' => $employee->employee_id, 'position' => $primary?->code ?? '', 'reports_to_position' => $primary?->reportsTo?->code ?? '', 'manager' => $manager?->employee_id ?? '', 'status' => $resolution['status']];
                    }
                }
                EmployeeReportingService::$deferred = false;
                $service->synchronize();
                foreach ($employees as $code => $employee) {
                    $line = array_key_first($employeeRows[$code]);
                    $importer->provisionLogin($employee->fresh(), $seenValues[$code] ?? []);
                    $importer->assignHq($employee->fresh(), $seenValues[$code] ?? []);
                }

                return $result;
            });
        } catch (Throwable $exception) {
            $message = match (true) {
                $exception instanceof ImportRowException => $exception->getMessage(),
                $exception instanceof ValidationException => collect($exception->errors())->flatten()->implode(' '),
                $exception instanceof AuthorizationException => 'You do not have permission to change these employees or positions.',
                default => 'The import could not be saved. Check the file and try again.',
            };
            if (! $exception instanceof ImportRowException && ! $exception instanceof ValidationException && ! $exception instanceof AuthorizationException) {
                report($exception);
            }

            return new ImportResult(failed: [['row' => $line, 'message' => $message.' No rows were saved.']]);
        } finally {
            EmployeeReportingService::$deferred = $previous;
        }
    }

    private function assertAcyclic(array $graph, int $start, string $kind = 'position'): void
    {
        $seen = [];
        $current = $start;
        while ($current !== null) {
            if (isset($seen[$current])) {
                throw new ImportRowException('Circular '.$kind.' reporting chain or self-reporting detected.');
            }
            $seen[$current] = true;
            $current = $graph[$current] ?? null;
        }
    }
}
