<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Services\HR\EmployeeRegisterFields;

class ProfessionalTaxRelationManager extends EmployeeRelationManager
{
    protected static string $relationship = 'professionalTax';

    protected static string $employeeSection = 'professional-tax';

    protected static ?string $title = EmployeeRegisterFields::LABELS['professional-tax'];
}
