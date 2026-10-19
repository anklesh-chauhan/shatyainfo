<?php

namespace App\Filament\Resources\HrChangeRequests\Pages;

use App\Filament\Resources\HrChangeRequests\HrChangeRequestResource;
use App\Services\HR\HrChangeService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class ManageHrChangeRequests extends ManageRecords
{
    protected static string $resource = HrChangeRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Request HR Change')->using(function (array $data): Model {
            try {
                return app(HrChangeService::class)->submit($data['request_type'], (int) $data['target_id'], $data['proposed_values'], $data['reason']);
            } catch (\LogicException $exception) {
                throw ValidationException::withMessages(['approval' => $exception->getMessage()]);
            }
        })];
    }
}
