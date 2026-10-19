<?php

namespace App\Filament\Resources\SalesInvoices\Pages;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Resources\SalesInvoices\SalesInvoiceResource;
use App\Traits\SalesDocumentResourceTrait;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSalesInvoice extends EditRecord
{
    use SalesDocumentResourceTrait;

    protected static string $resource = SalesInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ApprovalAction::make(),
            DeleteAction::make(),
        ];
    }
}
