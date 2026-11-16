<?php

namespace App\Filament\Resources\SalesDcrExpenses\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class SalesDcrExpenseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('sales_dcr_id')
                    ->relationship('salesDcr', 'id', modifyQueryUsing: fn (Builder $query): Builder => $query->applyVisibility('SalesDcr'))
                    ->disabledOn('edit')
                    ->required(),
                Select::make('expense_type_id')
                    ->relationship('expenseType', 'name')
                    ->required(),
                Select::make('transport_mode_id')
                    ->relationship('transportMode', 'name'),
                TextInput::make('amount')
                    ->required()
                    ->numeric(),
                Toggle::make('is_auto_calculated')
                    ->required(),
                TextInput::make('quantity')
                    ->numeric(),
                TextInput::make('rate')
                    ->numeric(),
                TextInput::make('meta'),
                Textarea::make('remarks')
                    ->columnSpanFull(),
            ]);
    }
}
