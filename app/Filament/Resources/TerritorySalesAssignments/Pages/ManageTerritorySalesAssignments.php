<?php

namespace App\Filament\Resources\TerritorySalesAssignments\Pages;

use App\Filament\Resources\TerritorySalesAssignments\TerritorySalesAssignmentResource;
use App\Services\Field\SalesPerformanceService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTerritorySalesAssignments extends ManageRecords
{
    protected static string $resource = TerritorySalesAssignmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Assign territory')->using(fn (array $data) => app(SalesPerformanceService::class)->assign(auth()->user(), $data)),
        ];
    }
}
