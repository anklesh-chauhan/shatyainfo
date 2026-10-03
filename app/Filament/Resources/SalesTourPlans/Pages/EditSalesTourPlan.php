<?php

namespace App\Filament\Resources\SalesTourPlans\Pages;

use App\Filament\Resources\SalesTourPlans\SalesTourPlanResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSalesTourPlan extends EditRecord
{
    protected static string $resource = SalesTourPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
