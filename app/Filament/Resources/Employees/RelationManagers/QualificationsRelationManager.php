<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Services\HR\EmployeeRegisterFields;

class QualificationsRelationManager extends EmployeeRelationManager
{
    protected static string $relationship = 'qualifications';

    protected static string $employeeSection = 'qualifications';

    protected static ?string $title = EmployeeRegisterFields::LABELS['qualifications'];
}
