<?php

namespace App\Filament\Resources\SampleRequests\Pages;

use App\Filament\Resources\SampleRequests\SampleRequestResource;
use App\Services\Inventory\SampleRequestRegister;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateSampleRequest extends CreateRecord
{
    protected static string $resource = SampleRequestResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(SampleRequestRegister::class)->save(auth()->user(), null, $data);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $errors, string $key): array => ['data.'.$key => $errors])->all());
        }
    }
}
