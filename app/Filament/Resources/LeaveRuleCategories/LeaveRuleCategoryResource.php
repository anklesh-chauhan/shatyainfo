<?php

namespace App\Filament\Resources\LeaveRuleCategories;

use App\Filament\Clusters\HR\ShiftAndCalanderCluster;
use App\Filament\Resources\LeaveRuleCategories\Pages\CreateLeaveRuleCategory;
use App\Filament\Resources\LeaveRuleCategories\Pages\EditLeaveRuleCategory;
use App\Filament\Resources\LeaveRuleCategories\Pages\ListLeaveRuleCategories;
use App\Filament\Resources\LeaveRuleCategories\Schemas\LeaveRuleCategoryForm;
use App\Filament\Resources\LeaveRuleCategories\Tables\LeaveRuleCategoriesTable;
use App\Models\LeaveRuleCategory;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class LeaveRuleCategoryResource extends Resource
{
    protected static ?string $cluster = ShiftAndCalanderCluster::class;

    protected static ?string $model = LeaveRuleCategory::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedFolderOpen;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 100;

    protected static ?string $navigationLabel = 'Leave Categories';

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
