<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Services\HR\EmployeeRegisterFields;

class EmploymentDetailRelationManager extends EmployeeRelationManager
{
    protected static string $relationship = 'employmentDetail';

    protected static string $employeeSection = 'employment';

    protected static ?string $title = EmployeeRegisterFields::LABELS['employment'];
}
