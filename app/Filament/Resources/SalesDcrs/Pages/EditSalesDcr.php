<?php

namespace App\Filament\Resources\SalesDcrs\Pages;

use App\AutoCalculateExpensesAction;
use App\Filament\Actions\ApprovalAction;
use App\Filament\Resources\SalesDcrs\SalesDcrResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditSalesDcr extends EditRecord
{
    protected static string $resource = SalesDcrResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ApprovalAction::make(),
            AutoCalculateExpensesAction::make(),
            ViewAction::make(),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
