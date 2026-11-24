<?php

namespace App\Filament\Resources\InventoryAdjustments\Pages;

use App\Filament\Resources\InventoryAdjustments\InventoryAdjustmentResource;
use App\Services\Inventory\StockAdjustmentRegister;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateInventoryAdjustment extends CreateRecord
{
    protected static string $resource = InventoryAdjustmentResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(StockAdjustmentRegister::class)->save(auth()->user(), null, $data);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $errors, string $key): array => ['data.'.$key => $errors])->all());
        }
    }
}
