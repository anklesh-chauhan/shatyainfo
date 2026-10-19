<?php

namespace App\Filament\Resources\AccountStatuses\Pages;

use App\Filament\Resources\AccountStatuses\AccountStatusResource;
use Filament\Resources\Pages\EditRecord;

class EditAccountStatus extends EditRecord
{
    protected static string $resource = AccountStatusResource::class;

    protected function getHeaderActions(): array
    {
        return [

        ];
    }
}
