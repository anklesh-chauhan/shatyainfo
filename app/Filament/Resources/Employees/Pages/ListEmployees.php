<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Pages\DataImport;
use App\Filament\Resources\Employees\EmployeeResource;
use Filament\Actions;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmployees extends ListRecords
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('importEmployees')
                ->label('Import employees')
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(fn (): bool => EmployeeResource::canCreate())
                ->url(fn (): string => DataImport::getUrl(['module' => 'employees'])),
            CreateAction::make(),
        ];
    }
}
