<?php

namespace App\Filament\Resources\FieldDevices\Pages;

use App\Filament\Resources\FieldDevices\FieldDeviceResource;
use Filament\Resources\Pages\ManageRecords;

class ManageFieldDevices extends ManageRecords
{
    protected static string $resource = FieldDeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
