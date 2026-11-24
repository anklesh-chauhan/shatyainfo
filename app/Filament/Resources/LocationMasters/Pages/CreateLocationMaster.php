<?php

namespace App\Filament\Resources\LocationMasters\Pages;

use App\Filament\Resources\LocationMasters\LocationMasterResource;
use App\Services\Inventory\LocationRegister;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateLocationMaster extends CreateRecord
{
    protected static string $resource = LocationMasterResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(LocationRegister::class)->save(auth()->user(), null, LocationMasterResource::adminInput($data));
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $errors, string $key): array => ['data.'.$key => $errors])->all());
        }
    }
}
