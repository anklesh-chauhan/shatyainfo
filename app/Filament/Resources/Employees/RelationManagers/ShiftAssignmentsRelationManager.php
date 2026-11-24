<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Services\HR\EmployeeRegisterFields;

class ShiftAssignmentsRelationManager extends EmployeeRelationManager
{
    protected static string $relationship = 'shiftAssignments';

    protected static string $employeeSection = 'shifts';

    protected static ?string $title = EmployeeRegisterFields::LABELS['shifts'];
}
