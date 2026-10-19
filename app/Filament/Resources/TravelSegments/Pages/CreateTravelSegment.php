<?php

namespace App\Filament\Resources\TravelSegments\Pages;

use App\Filament\Resources\TravelSegments\TravelSegmentResource;
use App\Services\Travel\TravelSegmentValidation;
use Filament\Resources\Pages\CreateRecord;

class CreateTravelSegment extends CreateRecord
{
    protected static string $resource = TravelSegmentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        app(TravelSegmentValidation::class)->validate($data);

        return $data;
    }
}
