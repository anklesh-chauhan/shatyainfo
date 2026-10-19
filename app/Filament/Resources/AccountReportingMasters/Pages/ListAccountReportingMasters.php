<?php

namespace App\Filament\Resources\AccountReportingMasters\Pages;

use App\Filament\Resources\AccountReportingMasters\AccountReportingMasterResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAccountReportingMasters extends ListRecords
{
    protected static string $resource = AccountReportingMasterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
