<?php

namespace App\Filament\Resources\CustomerPrices\Pages;

use App\Filament\Resources\CustomerPrices\CustomerPriceResource;
use App\Models\CustomerPrice;
use App\Services\Field\FieldCustomerPriceService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCustomerPrice extends EditRecord
{
    protected static string $resource = CustomerPriceResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(FieldCustomerPriceService::class)->save(auth()->user(), $record->getKey(), $data);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (CustomerPrice $record): bool => app(FieldCustomerPriceService::class)->canChange(auth()->user(), $record, 'delete'))
                ->using(fn (CustomerPrice $record): bool => app(FieldCustomerPriceService::class)->delete(auth()->user(), [$record->id]) === 1),
        ];
    }
}
