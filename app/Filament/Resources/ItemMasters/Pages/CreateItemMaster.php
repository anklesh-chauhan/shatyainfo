<?php

namespace App\Filament\Resources\ItemMasters\Pages;

use App\Filament\Resources\ItemMasters\ItemMasterResource;
use App\Services\Inventory\ItemRegister;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateItemMaster extends CreateRecord
{
    protected static string $resource = ItemMasterResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(ItemRegister::class)->save(auth()->user(), null, $data);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $errors, string $key): array => ['data.'.$key => $errors])->all());
        }
    }
}
