<?php

namespace App\Filament\Resources\Positions\Pages;

use App\Filament\Resources\Positions\PositionResource;
use App\Services\HR\PositionRegister;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPosition extends ViewRecord
{
    protected static string $resource = PositionResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return app(PositionRegister::class)->detail(auth()->user(), $this->record);
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
