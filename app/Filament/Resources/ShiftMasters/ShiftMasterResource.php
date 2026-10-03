<?php

namespace App\Filament\Resources\ShiftMasters;

use App\Filament\Clusters\HR\ShiftAndCalanderCluster;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\ShiftMasters\Pages\CreateShiftMaster;
use App\Filament\Resources\ShiftMasters\Pages\EditShiftMaster;
use App\Filament\Resources\ShiftMasters\Pages\ListShiftMasters;
use App\Filament\Resources\ShiftMasters\Schemas\ShiftMasterForm;
use App\Filament\Resources\ShiftMasters\Tables\ShiftMastersTable;
use App\Models\ShiftMaster;
use App\Traits\HasSafeGlobalSearch;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ShiftMasterResource extends BaseResource
{
    use HasSafeGlobalSearch;

    protected static ?string $model = ShiftMaster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $cluster = ShiftAndCalanderCluster::class;

    protected static ?string $navigationLabel = 'Shift Master';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return ShiftMasterForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ShiftMastersTable::configure($table);
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
            'index' => ListShiftMasters::route('/'),
            'create' => CreateShiftMaster::route('/create'),
            'edit' => EditShiftMaster::route('/{record}/edit'),
        ];
    }
}
