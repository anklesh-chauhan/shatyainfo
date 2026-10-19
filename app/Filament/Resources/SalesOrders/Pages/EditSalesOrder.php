<?php

namespace App\Filament\Resources\SalesOrders\Pages;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Traits\SalesDocumentResourceTrait;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSalesOrder extends EditRecord
{
    use SalesDocumentResourceTrait;

    protected static string $resource = SalesOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ApprovalAction::make(),
            DeleteAction::make(),
        ];
    }
}
