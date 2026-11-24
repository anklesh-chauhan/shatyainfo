<?php

namespace App\Filament\Resources\SgipDistributions\Pages;

use App\Filament\Resources\SgipDistributions\SgipDistributionResource;
use App\Services\Marketing\SgipRegister;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateSgipDistribution extends CreateRecord
{
    protected static string $resource = SgipDistributionResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(SgipRegister::class)->save(auth()->user(), null, $data);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $errors, string $key): array => ['data.'.$key => $errors])->all());
        }
    }
}
