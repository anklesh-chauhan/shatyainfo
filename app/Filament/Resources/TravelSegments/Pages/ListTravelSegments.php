<?php

namespace App\Filament\Resources\TravelSegments\Pages;

use App\Filament\Pages\FieldTravelImport;
use App\Filament\Resources\TravelSegments\TravelSegmentResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTravelSegments extends ListRecords
{
    protected static string $resource = TravelSegmentResource::class;

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
