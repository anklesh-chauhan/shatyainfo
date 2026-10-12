<?php

namespace App\Filament\Resources\EmployeeAttendanceStatuses;

use App\Filament\Clusters\HR\AttendanceCluster;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\EmployeeAttendanceStatuses\Pages\CreateEmployeeAttendanceStatus;
use App\Filament\Resources\EmployeeAttendanceStatuses\Pages\EditEmployeeAttendanceStatus;
use App\Filament\Resources\EmployeeAttendanceStatuses\Pages\ListEmployeeAttendanceStatuses;
use App\Filament\Resources\EmployeeAttendanceStatuses\Schemas\EmployeeAttendanceStatusForm;
use App\Filament\Resources\EmployeeAttendanceStatuses\Tables\EmployeeAttendanceStatusesTable;
use App\Models\EmployeeAttendanceStatus;
use App\Traits\HasSafeGlobalSearch;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class EmployeeAttendanceStatusResource extends BaseResource
{
    use HasSafeGlobalSearch;

    protected static ?string $model = EmployeeAttendanceStatus::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    protected static ?string $cluster = AttendanceCluster::class;

    protected static ?string $navigationLabel = 'Attendance Status';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return EmployeeAttendanceStatusForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EmployeeAttendanceStatusesTable::configure($table);
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
            'index' => ListEmployeeAttendanceStatuses::route('/'),
            'create' => CreateEmployeeAttendanceStatus::route('/create'),
            'edit' => EditEmployeeAttendanceStatus::route('/{record}/edit'),
        ];
    }
}
