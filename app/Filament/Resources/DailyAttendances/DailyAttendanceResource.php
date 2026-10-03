<?php

namespace App\Filament\Resources\DailyAttendances;

use App\Filament\Clusters\HR\AttendanceCluster;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\DailyAttendances\Pages\CreateDailyAttendance;
use App\Filament\Resources\DailyAttendances\Pages\EditDailyAttendance;
use App\Filament\Resources\DailyAttendances\Pages\ListDailyAttendances;
use App\Filament\Resources\DailyAttendances\Schemas\DailyAttendanceForm;
use App\Filament\Resources\DailyAttendances\Tables\DailyAttendancesTable;
use App\Models\DailyAttendance;
use App\Traits\HasSafeGlobalSearch;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DailyAttendanceResource extends BaseResource
{
    use HasSafeGlobalSearch;

    protected static ?string $model = DailyAttendance::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = null;

    protected static ?string $cluster = AttendanceCluster::class;

    protected static ?int $navigationSort = 20;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Filament::auth()->user();

        // OWN RECORDS ONLY
        if ($user->can('ViewOwn:DailyAttendance')) {
            return $query->ownedBy($user);
        } else {
            return $query;
        }

        // NO ACCESS
        return $query->whereRaw('1 = 0');
    }

    public static function form(Schema $schema): Schema
    {
        return DailyAttendanceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DailyAttendancesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PunchesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDailyAttendances::route('/'),
            'create' => CreateDailyAttendance::route('/create'),
            'edit' => EditDailyAttendance::route('/{record}/edit'),
        ];
    }
}
