<?php

namespace App\Filament\Resources\Organizations\Pages;

use App\Filament\Resources\Organizations\OrganizationResource;
use App\Services\DateFormatService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditOrganization extends EditRecord
{
    protected static string $resource = OrganizationResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [...$data, 'date_format' => app(DateFormatService::class)->dateFormat()];
    }

    protected function afterSave(): void
    {
        if (auth()->user()?->hasAnyRole(['super_admin', 'administration_admin']) && isset($this->data['date_format'])) {
            app(DateFormatService::class)->save($this->data['date_format']);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
