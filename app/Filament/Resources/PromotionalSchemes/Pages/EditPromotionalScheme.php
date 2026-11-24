<?php

namespace App\Filament\Resources\PromotionalSchemes\Pages;

use App\Filament\Resources\PromotionalSchemes\PromotionalSchemeResource;
use App\Http\Requests\Api\V1\SavePromotionalSchemeRequest;
use App\Services\Marketing\PromotionalSchemeRegister;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

class EditPromotionalScheme extends EditRecord
{
    protected static string $resource = PromotionalSchemeResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $detail = app(PromotionalSchemeRegister::class)->detail(auth()->user(), $this->record);
        $data['revision'] = $detail['revision'];
        $data['applies_to_id'] = $detail['target']?->id;
        $data['benefits'] = array_map(fn (array $benefit): array => Arr::except($benefit, ['item_name']), $detail['benefits']);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $input = Validator::make($data, SavePromotionalSchemeRequest::configurationRules(true))->validate();

        return app(PromotionalSchemeRegister::class)->save(auth()->user(), $record->getKey(), $input);
    }

    protected function afterSave(): void
    {
        $this->record->refresh();
        $this->form->fill($this->mutateFormDataBeforeFill($this->record->attributesToArray()));
    }
}
