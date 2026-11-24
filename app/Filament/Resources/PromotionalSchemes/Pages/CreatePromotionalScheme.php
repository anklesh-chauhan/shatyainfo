<?php

namespace App\Filament\Resources\PromotionalSchemes\Pages;

use App\Filament\Resources\PromotionalSchemes\PromotionalSchemeResource;
use App\Http\Requests\Api\V1\SavePromotionalSchemeRequest;
use App\Services\Marketing\PromotionalSchemeRegister;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class CreatePromotionalScheme extends CreateRecord
{
    protected static string $resource = PromotionalSchemeResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $input = Validator::make($data, SavePromotionalSchemeRequest::configurationRules(false))->validate();

        return app(PromotionalSchemeRegister::class)->save(auth()->user(), null, $input);
    }
}
