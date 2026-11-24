<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Services\HR\EmployeeRegisterFields;

class SkillsRelationManager extends EmployeeRelationManager
{
    protected static string $relationship = 'skills';

    protected static string $employeeSection = 'skills';

    protected static ?string $title = EmployeeRegisterFields::LABELS['skills'];
}
