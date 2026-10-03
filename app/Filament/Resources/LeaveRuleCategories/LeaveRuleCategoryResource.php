<?php

namespace App\Filament\Resources\LeaveRuleCategories;

use App\Filament\Clusters\HR\LeaveManagementCluster;
use App\Filament\Resources\LeaveRuleCategories\Pages\CreateLeaveRuleCategory;
use App\Filament\Resources\LeaveRuleCategories\Pages\EditLeaveRuleCategory;
use App\Filament\Resources\LeaveRuleCategories\Pages\ListLeaveRuleCategories;
use App\Filament\Resources\LeaveRuleCategories\Schemas\LeaveRuleCategoryForm;
use App\Filament\Resources\LeaveRuleCategories\Tables\LeaveRuleCategoriesTable;
use App\Models\LeaveRuleCategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class LeaveRuleCategoryResource extends Resource
{
    protected static ?string $model = LeaveRuleCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $cluster = LeaveManagementCluster::class;

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Cetegories';

    public static function form(Schema $schema): Schema
    {
        return LeaveRuleCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LeaveRuleCategoriesTable::configure($table);
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
            'index' => ListLeaveRuleCategories::route('/'),
            'create' => CreateLeaveRuleCategory::route('/create'),
            'edit' => EditLeaveRuleCategory::route('/{record}/edit'),
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
