<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Services\HR\EmployeeRegisterFields;

class PositionsRelationManager extends EmployeeRelationManager
{
    protected static string $relationship = 'positions';

    protected static string $employeeSection = 'positions';

    protected static ?string $title = EmployeeRegisterFields::LABELS['positions'];
}
