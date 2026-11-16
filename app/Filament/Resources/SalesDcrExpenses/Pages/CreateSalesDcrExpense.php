<?php

namespace App\Filament\Resources\SalesDcrExpenses\Pages;

use App\Filament\Resources\SalesDcrExpenses\SalesDcrExpenseResource;
use App\Models\SalesDcr;
use Filament\Resources\Pages\CreateRecord;

class CreateSalesDcrExpense extends CreateRecord
{
    protected static string $resource = SalesDcrExpenseResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        SalesDcr::query()->applyVisibility('SalesDcr')->findOrFail($data['sales_dcr_id']);

        return $data;
    }
}
