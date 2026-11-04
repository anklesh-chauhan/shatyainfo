<?php

namespace App\Filament\Resources\SecondarySalesCorrections\Pages;

use App\Filament\Resources\SecondarySalesCorrections\SecondarySalesCorrectionResource;
use Filament\Resources\Pages\ManageRecords;

class ManageSecondarySalesCorrections extends ManageRecords
{
    protected static string $resource = SecondarySalesCorrectionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
