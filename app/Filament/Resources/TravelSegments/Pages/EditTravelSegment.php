<?php

namespace App\Filament\Resources\TravelSegments\Pages;

use App\Filament\Resources\TravelSegments\TravelSegmentResource;
use App\Services\Travel\TravelSegmentValidation;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditTravelSegment extends EditRecord
{
    protected static string $resource = TravelSegmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        app(TravelSegmentValidation::class)->validate(['sales_dcr_id' => $this->record->sales_dcr_id]);
        app(TravelSegmentValidation::class)->validate($data);

        return $data;
    }
}
