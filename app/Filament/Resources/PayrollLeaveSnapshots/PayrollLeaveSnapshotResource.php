<?php

namespace App\Filament\Resources\PayrollLeaveSnapshots;

use App\Filament\Resources\PayrollLeaveSnapshots\Pages\CreatePayrollLeaveSnapshot;
use App\Filament\Resources\PayrollLeaveSnapshots\Pages\EditPayrollLeaveSnapshot;
use App\Filament\Resources\PayrollLeaveSnapshots\Pages\ListPayrollLeaveSnapshots;
use App\Filament\Resources\PayrollLeaveSnapshots\Schemas\PayrollLeaveSnapshotForm;
use App\Filament\Resources\PayrollLeaveSnapshots\Tables\PayrollLeaveSnapshotsTable;
use App\Models\PayrollLeaveSnapshot;
use App\Traits\HasRelatedGlobalSearch;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PayrollLeaveSnapshotResource extends Resource
{
    use HasRelatedGlobalSearch;

    protected static ?string $model = PayrollLeaveSnapshot::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static ?string $recordTitleAttribute = null;

    protected static array $relatedGlobalSearchAttributes = [
        'employee.first_name',
        'employee.last_name',
        'employee.employee_id',
        'leaveType.name',
    ];

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return PayrollLeaveSnapshotForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PayrollLeaveSnapshotsTable::configure($table);
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
            'index' => ListPayrollLeaveSnapshots::route('/'),
            'create' => CreatePayrollLeaveSnapshot::route('/create'),
            'edit' => EditPayrollLeaveSnapshot::route('/{record}/edit'),
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
