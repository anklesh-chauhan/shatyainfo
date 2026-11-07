<?php

namespace App\Filament\Resources\Organizations\Pages;

use App\Filament\Resources\Organizations\OrganizationResource;
use App\Services\DateFormatService;
use Filament\Resources\Pages\CreateRecord;

class CreateOrganization extends CreateRecord
{
    protected static string $resource = OrganizationResource::class;

    protected function afterCreate(): void
    {
        if (auth()->user()?->hasAnyRole(['super_admin', 'administration_admin']) && isset($this->data['date_format'])) {
            app(DateFormatService::class)->save($this->data['date_format']);
        }
    }
}
