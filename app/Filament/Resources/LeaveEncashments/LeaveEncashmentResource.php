<?php

namespace App\Filament\Resources\LeaveEncashments;

use App\Filament\Clusters\HR\LeaveManagementCluster;
use App\Filament\Resources\LeaveEncashments\Pages\CreateLeaveEncashment;
use App\Filament\Resources\LeaveEncashments\Pages\EditLeaveEncashment;
use App\Filament\Resources\LeaveEncashments\Pages\ListLeaveEncashments;
use App\Filament\Resources\LeaveEncashments\Schemas\LeaveEncashmentForm;
use App\Filament\Resources\LeaveEncashments\Tables\LeaveEncashmentsTable;
use App\Models\LeaveEncashment;
use App\Traits\HasRelatedGlobalSearch;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class LeaveEncashmentResource extends Resource
{
    use HasRelatedGlobalSearch;

    protected static ?string $model = LeaveEncashment::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $recordTitleAttribute = 'payroll_month';

    protected static array $relatedGlobalSearchAttributes = [
        'employee.first_name',
        'employee.last_name',
        'employee.employee_id',
        'leaveType.name',
        'payroll_month',
    ];

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $cluster = LeaveManagementCluster::class;

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Encashment';

    public static function form(Schema $schema): Schema
    {
        return LeaveEncashmentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LeaveEncashmentsTable::configure($table);
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
            'index' => ListLeaveEncashments::route('/'),
            'create' => CreateLeaveEncashment::route('/create'),
            'edit' => EditLeaveEncashment::route('/{record}/edit'),
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
