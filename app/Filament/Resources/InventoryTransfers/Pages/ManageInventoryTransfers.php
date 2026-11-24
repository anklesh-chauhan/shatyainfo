<?php

namespace App\Filament\Resources\InventoryTransfers\Pages;

use App\Filament\Resources\InventoryTransfers\InventoryTransferResource;
use App\Models\InventoryTransfer;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageInventoryTransfers extends ManageRecords
{
    protected static string $resource = InventoryTransferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->using(fn (array $data, CreateAction $action): InventoryTransfer => InventoryTransferResource::persist(null, $data, $action)),
        ];
    }
}
