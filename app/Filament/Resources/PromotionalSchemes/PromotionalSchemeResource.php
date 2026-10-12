<?php

namespace App\Filament\Resources\PromotionalSchemes;

use App\Filament\Resources\PromotionalSchemes\Pages\CreatePromotionalScheme;
use App\Filament\Resources\PromotionalSchemes\Pages\EditPromotionalScheme;
use App\Filament\Resources\PromotionalSchemes\Pages\ListPromotionalSchemes;
use App\Filament\Resources\PromotionalSchemes\Schemas\PromotionalSchemeForm;
use App\Filament\Resources\PromotionalSchemes\Tables\PromotionalSchemesTable;
use App\Models\PromotionalScheme;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PromotionalSchemeResource extends Resource
{
    protected static ?string $model = PromotionalScheme::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|\UnitEnum|null $navigationGroup = 'Marketing & Field Sales';

    protected static ?string $navigationLabel = 'Promotional Schemes';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return PromotionalSchemeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PromotionalSchemesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPromotionalSchemes::route('/'),
            'create' => CreatePromotionalScheme::route('/create'),
            'edit' => EditPromotionalScheme::route('/{record}/edit'),
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
