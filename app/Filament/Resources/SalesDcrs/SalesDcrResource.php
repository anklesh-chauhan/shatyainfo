<?php

namespace App\Filament\Resources\SalesDcrs;

use App\Filament\Resources\SalesDcrs\Pages\CreateSalesDcr;
use App\Filament\Resources\SalesDcrs\Pages\EditSalesDcr;
use App\Filament\Resources\SalesDcrs\Pages\ListSalesDcrs;
use App\Filament\Resources\SalesDcrs\Pages\ViewSalesDcr;
use App\Filament\Resources\SalesDcrs\RelationManagers\SalesDcrExpensesRelationManager;
use App\Filament\Resources\SalesDcrs\RelationManagers\SalesDcrVisitsRelationManager;
use App\Filament\Resources\SalesDcrs\Schemas\SalesDcrForm;
use App\Filament\Resources\SalesDcrs\Schemas\SalesDcrInfolist;
use App\Filament\Resources\SalesDcrs\Tables\SalesDcrsTable;
use App\Models\SalesDcr;
use App\Traits\HasRelatedGlobalSearch;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SalesDcrResource extends Resource
{
    use HasRelatedGlobalSearch;

    protected static ?string $model = SalesDcr::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $recordTitleAttribute = 'remarks';

    protected static array $relatedGlobalSearchAttributes = [
        'user.name',
        'user.email',
        'remarks',
        'approval_status',
    ];

    protected static string|\UnitEnum|null $navigationGroup = 'Marketing & Field Sales';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Sales DCR';

    public static function form(Schema $schema): Schema
    {
        return SalesDcrForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SalesDcrInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SalesDcrsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            SalesDcrVisitsRelationManager::class,
            SalesDcrExpensesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSalesDcrs::route('/'),
            'create' => CreateSalesDcr::route('/create'),
            // 'view' => ViewSalesDcr::route('/{record}'),
            'edit' => EditSalesDcr::route('/{record}/edit'),
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
