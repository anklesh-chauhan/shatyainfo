<?php

namespace App\Filament\Resources\AccountStatuses\Pages;

use App\Filament\Resources\AccountStatuses\AccountStatusResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAccountStatus extends CreateRecord
{
    protected static string $resource = AccountStatusResource::class;
}
