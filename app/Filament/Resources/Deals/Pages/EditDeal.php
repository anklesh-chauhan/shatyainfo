<?php

namespace App\Filament\Resources\Deals\Pages;

use App\Filament\Resources\Deals\DealResource;
use App\Models\Deal;
use App\Services\SalespersonAi\NextActionService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDeal extends EditRecord
{
    protected static string $resource = DealResource::class;

    public function getSubheading(): ?string
    {
        $record = $this->getRecord();

        if (! $record instanceof Deal) {
            return null;
        }

        return app(NextActionService::class)->forDeal($record)['summary'];
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
