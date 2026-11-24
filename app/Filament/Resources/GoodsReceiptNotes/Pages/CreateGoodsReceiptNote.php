<?php

namespace App\Filament\Resources\GoodsReceiptNotes\Pages;

use App\Filament\Resources\GoodsReceiptNotes\GoodsReceiptNoteResource;
use App\Services\Inventory\GoodsReceiptRegister;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateGoodsReceiptNote extends CreateRecord
{
    protected static string $resource = GoodsReceiptNoteResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(GoodsReceiptRegister::class)->save(auth()->user(), null, $data);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $errors, string $key): array => ['data.'.$key => $errors])->all());
        }
    }
}
