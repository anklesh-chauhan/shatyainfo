<?php

namespace App\Filament\Resources\SgipLimits;

use App\Filament\Resources\SgipLimits\Pages\CreateSgipLimit;
use App\Filament\Resources\SgipLimits\Pages\EditSgipLimit;
use App\Filament\Resources\SgipLimits\Pages\ListSgipLimits;
use App\Filament\Resources\SgipLimits\Pages\ViewSgipLimit;
use App\Filament\Resources\SgipLimits\Schemas\SgipLimitForm;
use App\Filament\Resources\SgipLimits\Schemas\SgipLimitInfolist;
use App\Filament\Resources\SgipLimits\Tables\SgipLimitsTable;
use App\Models\SgipLimit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SgipLimitResource extends Resource
{
    protected static ?string $model = SgipLimit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'item_type';

    protected static string|\UnitEnum|null $navigationGroup = 'Marketing & Field Sales';

    public static function getGloballySearchableAttributes(): array
    {
        return ['item_type', 'applies_to', 'period'];
    }

    public static function form(Schema $schema): Schema
    {
        return SgipLimitForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SgipLimitInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SgipLimitsTable::configure($table);
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
            'index' => ListSgipLimits::route('/'),
            'create' => CreateSgipLimit::route('/create'),
            'view' => ViewSgipLimit::route('/{record}'),
            'edit' => EditSgipLimit::route('/{record}/edit'),
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
