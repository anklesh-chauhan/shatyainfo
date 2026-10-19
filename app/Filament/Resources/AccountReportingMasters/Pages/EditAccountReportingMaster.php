<?php

namespace App\Filament\Resources\AccountReportingMasters\Pages;

use App\Filament\Resources\AccountReportingMasters\AccountReportingMasterResource;
use Filament\Resources\Pages\EditRecord;

class EditAccountReportingMaster extends EditRecord
{
    protected static string $resource = AccountReportingMasterResource::class;

    protected function getHeaderActions(): array
    {
        return [

        ];
    }
}
