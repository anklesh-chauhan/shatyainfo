<?php

namespace App\Filament\Resources\Tenants\Pages;

use App\Filament\Resources\Tenants\TenantResource;
use App\Models\Tenant;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;

    /**
     * @var array{name: string, email: string, password: string}|null
     */
    protected ?array $owner = null;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $raw = $this->form->getRawState();

        $this->owner = [
            'name' => (string) ($raw['owner_name'] ?? ''),
            'email' => (string) ($raw['owner_email'] ?? ''),
            'password' => (string) ($raw['owner_password'] ?? ''),
        ];

        unset($data['owner_name'], $data['owner_email'], $data['owner_password'], $data['database']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        Tenant::$pendingOwner = $this->owner;

        return parent::handleRecordCreation($data);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Company saved. Database setup is running in the background.';
    }
}
