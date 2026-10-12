<?php

namespace App\Filament\Resources\Organizations\Pages;

use App\Filament\Imports\OrganizationImporter;
use App\Filament\Resources\Organizations\OrganizationResource;
use Filament\Actions\CreateAction;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;

class ListOrganizations extends ListRecords
{
    protected static string $resource = OrganizationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ImportAction::make()
                ->importer(OrganizationImporter::class)
                ->label('Import organizations')
                ->icon('heroicon-o-arrow-up-tray')
                ->authorize(fn (): bool => OrganizationResource::canCreate()),
            CreateAction::make(),
        ];
    }
}
