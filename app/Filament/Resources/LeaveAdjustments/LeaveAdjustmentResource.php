<?php

namespace App\Filament\Resources\LeaveAdjustments;

use App\Filament\Clusters\HR\LeaveManagementCluster;
use App\Filament\Resources\LeaveAdjustments\Pages\CreateLeaveAdjustment;
use App\Filament\Resources\LeaveAdjustments\Pages\EditLeaveAdjustment;
use App\Filament\Resources\LeaveAdjustments\Pages\ListLeaveAdjustments;
use App\Filament\Resources\LeaveAdjustments\Schemas\LeaveAdjustmentForm;
use App\Filament\Resources\LeaveAdjustments\Tables\LeaveAdjustmentsTable;
use App\Models\LeaveAdjustment;
use App\Traits\HasRelatedGlobalSearch;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class LeaveAdjustmentResource extends Resource
{
    use HasRelatedGlobalSearch;

    protected static ?string $model = LeaveAdjustment::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $recordTitleAttribute = 'reason';

    protected static array $relatedGlobalSearchAttributes = [
        'employee.first_name',
        'employee.last_name',
        'employee.employee_id',
        'leaveType.name',
        'reason',
        'type',
    ];

    protected static ?string $cluster = LeaveManagementCluster::class;

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Adjustment';

    public static function form(Schema $schema): Schema
    {
        return LeaveAdjustmentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LeaveAdjustmentsTable::configure($table);
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
            'index' => ListLeaveAdjustments::route('/'),
            'create' => CreateLeaveAdjustment::route('/create'),
            'edit' => EditLeaveAdjustment::route('/{record}/edit'),
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
