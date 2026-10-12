<?php

namespace App\Filament\Resources\SalesDcrExpenses;

use App\Filament\Resources\SalesDcrExpenses\Pages\CreateSalesDcrExpense;
use App\Filament\Resources\SalesDcrExpenses\Pages\EditSalesDcrExpense;
use App\Filament\Resources\SalesDcrExpenses\Pages\ListSalesDcrExpenses;
use App\Filament\Resources\SalesDcrExpenses\Schemas\SalesDcrExpenseForm;
use App\Filament\Resources\SalesDcrExpenses\Tables\SalesDcrExpensesTable;
use App\Models\SalesDcrExpense;
use App\Traits\HasRelatedGlobalSearch;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SalesDcrExpenseResource extends Resource
{
    use HasRelatedGlobalSearch;

    protected static ?string $model = SalesDcrExpense::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $recordTitleAttribute = 'remarks';

    protected static array $relatedGlobalSearchAttributes = [
        'remarks',
        'expenseType.name',
    ];

    protected static string|\UnitEnum|null $navigationGroup = 'Marketing & Field Sales';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Sales DCR Expenses';

    public static function form(Schema $schema): Schema
    {
        return SalesDcrExpenseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SalesDcrExpensesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSalesDcrExpenses::route('/'),
            'create' => CreateSalesDcrExpense::route('/create'),
            'edit' => EditSalesDcrExpense::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
