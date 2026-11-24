<?php

namespace App\Filament\Resources\MarketingCampaigns\Pages;

use App\Filament\Resources\MarketingCampaigns\MarketingCampaignResource;
use App\Http\Requests\Api\V1\SaveMarketingCampaignRequest;
use App\Services\Marketing\MarketingCampaignRegister;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

class EditMarketingCampaign extends EditRecord
{
    protected static string $resource = MarketingCampaignResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $detail = app(MarketingCampaignRegister::class)->detail(auth()->user(), $this->record);
        $data['revision'] = $detail['revision'];
        $data['items'] = array_map(fn (array $item): array => Arr::except($item, ['item_name']), $detail['items']);
        $data['territory_quotas'] = array_map(fn (array $quota): array => Arr::except($quota, ['item_name', 'territory_name', 'remaining_quantity']), $detail['territory_quotas']);
        if ($detail['scheme'] === null) {
            $data['promotional_scheme_id'] = null;
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $input = Validator::make($data, SaveMarketingCampaignRequest::configurationRules(true))->validate();
        $saved = app(MarketingCampaignRegister::class)->save(auth()->user(), $record->getKey(), $input);
        $this->data['revision'] = app(MarketingCampaignRegister::class)->detail(auth()->user(), $saved)['revision'];

        return $saved;
    }
}
