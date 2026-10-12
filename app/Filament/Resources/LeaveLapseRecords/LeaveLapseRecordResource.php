<?php

namespace App\Filament\Resources\LeaveLapseRecords;

use App\Filament\Clusters\HR\LeaveManagementCluster;
use App\Filament\Resources\LeaveLapseRecords\Pages\CreateLeaveLapseRecord;
use App\Filament\Resources\LeaveLapseRecords\Pages\EditLeaveLapseRecord;
use App\Filament\Resources\LeaveLapseRecords\Pages\ListLeaveLapseRecords;
use App\Filament\Resources\LeaveLapseRecords\Schemas\LeaveLapseRecordForm;
use App\Filament\Resources\LeaveLapseRecords\Tables\LeaveLapseRecordsTable;
use App\Models\LeaveLapseRecord;
use App\Traits\HasRelatedGlobalSearch;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class LeaveLapseRecordResource extends Resource
{
    use HasRelatedGlobalSearch;

    protected static ?string $model = LeaveLapseRecord::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $recordTitleAttribute = 'reason';

    protected static array $relatedGlobalSearchAttributes = [
        'employee.first_name',
        'employee.last_name',
        'employee.employee_id',
        'leaveType.name',
        'reason',
    ];

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $cluster = LeaveManagementCluster::class;

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Laps Records';

    public static function form(Schema $schema): Schema
    {
        return LeaveLapseRecordForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LeaveLapseRecordsTable::configure($table);
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
            'index' => ListLeaveLapseRecords::route('/'),
            'create' => CreateLeaveLapseRecord::route('/create'),
            'edit' => EditLeaveLapseRecord::route('/{record}/edit'),
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
