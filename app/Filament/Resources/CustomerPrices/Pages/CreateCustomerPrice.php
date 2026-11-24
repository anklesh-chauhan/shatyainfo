<?php

namespace App\Filament\Resources\CustomerPrices\Pages;

use App\Filament\Resources\CustomerPrices\CustomerPriceResource;
use App\Services\Field\FieldCustomerPriceService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCustomerPrice extends CreateRecord
{
    protected static string $resource = CustomerPriceResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(FieldCustomerPriceService::class)->save(auth()->user(), null, $data);
    }
}
