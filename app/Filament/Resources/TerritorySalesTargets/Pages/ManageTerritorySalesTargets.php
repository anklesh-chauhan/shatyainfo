<?php

namespace App\Filament\Resources\TerritorySalesTargets\Pages;

use App\Filament\Resources\TerritorySalesTargets\TerritorySalesTargetResource;
use App\Services\Field\SalesPerformanceService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTerritorySalesTargets extends ManageRecords
{
    protected static string $resource = TerritorySalesTargetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->using(fn (array $data) => app(SalesPerformanceService::class)->createTarget(auth()->user(), $data)),
        ];
    }
}
