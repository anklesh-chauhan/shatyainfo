<?php

namespace App\Filament\Resources\LeaveRules;

use App\Filament\Clusters\HR\LeaveManagementCluster;
use App\Filament\Resources\LeaveRules\Pages\CreateLeaveRule;
use App\Filament\Resources\LeaveRules\Pages\EditLeaveRule;
use App\Filament\Resources\LeaveRules\Pages\ListLeaveRules;
use App\Filament\Resources\LeaveRules\Pages\ViewLeaveRule;
use App\Filament\Resources\LeaveRules\Schemas\LeaveRuleForm;
use App\Filament\Resources\LeaveRules\Schemas\LeaveRuleInfolist;
use App\Filament\Resources\LeaveRules\Tables\LeaveRulesTable;
use App\Models\LeaveRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class LeaveRuleResource extends Resource
{
    protected static ?string $model = LeaveRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $cluster = LeaveManagementCluster::class;

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Rules';

    public static function form(Schema $schema): Schema
    {
        return LeaveRuleForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return LeaveRuleInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LeaveRulesTable::configure($table);
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
            'index' => ListLeaveRules::route('/'),
            'create' => CreateLeaveRule::route('/create'),
            'view' => ViewLeaveRule::route('/{record}'),
            'edit' => EditLeaveRule::route('/{record}/edit'),
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
