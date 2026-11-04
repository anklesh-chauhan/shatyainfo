<?php

namespace App\Filament\Resources\FieldSyncRecoveries\Pages;

use App\Filament\Resources\FieldSyncRecoveries\FieldSyncRecoveryResource;
use Filament\Resources\Pages\ManageRecords;

class ManageFieldSyncRecoveries extends ManageRecords
{
    protected static string $resource = FieldSyncRecoveryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
