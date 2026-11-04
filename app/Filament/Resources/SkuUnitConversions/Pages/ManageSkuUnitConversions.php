<?php

namespace App\Filament\Resources\SkuUnitConversions\Pages;

use App\Filament\Resources\SkuUnitConversions\SkuUnitConversionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSkuUnitConversions extends ManageRecords
{
    protected static string $resource = SkuUnitConversionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
