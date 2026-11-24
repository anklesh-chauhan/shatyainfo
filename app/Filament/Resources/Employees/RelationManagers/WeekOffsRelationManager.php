<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Services\HR\EmployeeRegisterFields;

class WeekOffsRelationManager extends EmployeeRelationManager
{
    protected static string $relationship = 'weekOffs';

    protected static string $employeeSection = 'week-offs';

    protected static ?string $title = EmployeeRegisterFields::LABELS['week-offs'];
}
