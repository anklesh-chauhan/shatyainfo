<?php

namespace App\Filament\Resources\RcpaCompetitors\Pages;

use App\Filament\Resources\RcpaCompetitors\RcpaCompetitorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageRcpaCompetitors extends ManageRecords
{
    protected static string $resource = RcpaCompetitorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
