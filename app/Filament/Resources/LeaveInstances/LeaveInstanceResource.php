<?php

namespace App\Filament\Resources\LeaveInstances;

use App\Filament\Clusters\HR\LeaveManagementCluster;
use App\Filament\Resources\LeaveInstances\Pages\CreateLeaveInstance;
use App\Filament\Resources\LeaveInstances\Pages\EditLeaveInstance;
use App\Filament\Resources\LeaveInstances\Pages\ListLeaveInstances;
use App\Filament\Resources\LeaveInstances\Pages\ViewLeaveInstance;
use App\Filament\Resources\LeaveInstances\Schemas\LeaveInstanceForm;
use App\Filament\Resources\LeaveInstances\Schemas\LeaveInstanceInfolist;
use App\Filament\Resources\LeaveInstances\Tables\LeaveInstancesTable;
use App\Models\LeaveInstance;
use App\Traits\HasRelatedGlobalSearch;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class LeaveInstanceResource extends Resource
{
    use HasRelatedGlobalSearch;

    protected static ?string $model = LeaveInstance::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'approval_status';

    protected static array $relatedGlobalSearchAttributes = [
        'employee.first_name',
        'employee.last_name',
        'employee.employee_id',
        'leaveType.name',
        'approval_status',
    ];

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $cluster = LeaveManagementCluster::class;

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Instances';

    public static function form(Schema $schema): Schema
    {
        return LeaveInstanceForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return LeaveInstanceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LeaveInstancesTable::configure($table);
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
            'index' => ListLeaveInstances::route('/'),
            'create' => CreateLeaveInstance::route('/create'),
            'view' => ViewLeaveInstance::route('/{record}'),
            'edit' => EditLeaveInstance::route('/{record}/edit'),
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
