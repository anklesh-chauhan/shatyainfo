<?php

namespace App\Filament\Resources\EDetailingMaterials\Pages;

use App\Filament\Resources\EDetailingMaterials\EDetailingMaterialResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEDetailingMaterials extends ListRecords
{
    protected static string $resource = EDetailingMaterialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
