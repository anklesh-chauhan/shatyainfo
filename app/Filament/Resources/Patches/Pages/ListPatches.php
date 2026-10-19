<?php

namespace App\Filament\Resources\Patches\Pages;

use App\Filament\Pages\FieldTravelImport;
use App\Filament\Resources\Patches\PatchResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPatches extends ListRecords
{
    protected static string $resource = PatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('importWorkbook')
                ->label('Import workbook')
                ->visible(fn (): bool => FieldTravelImport::canAccess())
                ->url(fn (): string => FieldTravelImport::getUrl()),
        ];
    }
}
