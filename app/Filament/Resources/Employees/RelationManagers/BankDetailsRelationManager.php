<?php

namespace App\Filament\Resources\Employees\RelationManagers;

class BankDetailsRelationManager extends EmployeeRelationManager
{
    protected static string $relationship = 'bankDetail';

    protected static string $employeeSection = 'banks';

    protected static ?string $title = 'Bank Details';
}
