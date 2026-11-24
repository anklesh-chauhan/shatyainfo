<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Services\HR\EmployeeRegisterFields;

class StatutoryIdsRelationManager extends EmployeeRelationManager
{
    protected static string $relationship = 'statutoryIds';

    protected static string $employeeSection = 'statutory';

    protected static ?string $title = EmployeeRegisterFields::LABELS['statutory'];
}
