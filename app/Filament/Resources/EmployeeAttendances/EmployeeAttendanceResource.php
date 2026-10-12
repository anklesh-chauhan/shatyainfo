<?php

namespace App\Filament\Resources\EmployeeAttendances;

use App\Filament\Clusters\HR\AttendanceCluster;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\EmployeeAttendances\Pages\CreateEmployeeAttendance;
use App\Filament\Resources\EmployeeAttendances\Pages\EditEmployeeAttendance;
use App\Filament\Resources\EmployeeAttendances\Pages\ListEmployeeAttendances;
use App\Filament\Resources\EmployeeAttendances\Pages\ViewEmployeeAttendance;
use App\Filament\Resources\EmployeeAttendances\Schemas\EmployeeAttendanceForm;
use App\Filament\Resources\EmployeeAttendances\Schemas\EmployeeAttendanceInfolist;
use App\Filament\Resources\EmployeeAttendances\Tables\EmployeeAttendancesTable;
use App\Models\EmployeeAttendance;
use App\Traits\HasSafeGlobalSearch;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class EmployeeAttendanceResource extends BaseResource
{
    use HasSafeGlobalSearch;

    protected static ?string $model = EmployeeAttendance::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static ?string $recordTitleAttribute = null;

    protected static ?string $cluster = AttendanceCluster::class;

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Attendances';

    public static function form(Schema $schema): Schema
    {
        return EmployeeAttendanceForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return EmployeeAttendanceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EmployeeAttendancesTable::configure($table);
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
            'index' => ListEmployeeAttendances::route('/'),
            'create' => CreateEmployeeAttendance::route('/create'),
            'view' => ViewEmployeeAttendance::route('/{record}'),
            'edit' => EditEmployeeAttendance::route('/{record}/edit'),
        ];
    }
}
