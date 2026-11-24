<?php

namespace App\Filament\Resources\MarketingCampaigns\Pages;

use App\Filament\Resources\MarketingCampaigns\MarketingCampaignResource;
use App\Http\Requests\Api\V1\SaveMarketingCampaignRequest;
use App\Services\Marketing\MarketingCampaignRegister;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

class CreateMarketingCampaign extends CreateRecord
{
    protected static string $resource = MarketingCampaignResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $input = Validator::make($data, SaveMarketingCampaignRequest::configurationRules(false))->validate();

        return app(MarketingCampaignRegister::class)->save(auth()->user(), null, $input);
    }
}
