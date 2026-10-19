<?php

namespace App\Filament\Resources\StandardFareCharts\Pages;

use App\Filament\Pages\FieldTravelImport;
use App\Filament\Resources\StandardFareCharts\StandardFareChartResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStandardFareCharts extends ListRecords
{
    protected static string $resource = StandardFareChartResource::class;

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
