<?php

namespace App\Filament\Resources\SgipDistributions;

use App\Filament\Resources\SgipDistributions\Pages\CreateSgipDistribution;
use App\Filament\Resources\SgipDistributions\Pages\EditSgipDistribution;
use App\Filament\Resources\SgipDistributions\Pages\ListSgipDistributions;
use App\Filament\Resources\SgipDistributions\Schemas\SgipDistributionForm;
use App\Filament\Resources\SgipDistributions\Tables\SgipDistributionsTable;
use App\Models\SgipDistribution;
use App\Traits\HasRelatedGlobalSearch;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SgipDistributionResource extends Resource
{
    use HasRelatedGlobalSearch;

    protected static ?string $model = SgipDistribution::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static ?string $recordTitleAttribute = 'approval_status';

    protected static array $relatedGlobalSearchAttributes = [
        'employee.first_name',
        'employee.last_name',
        'employee.employee_id',
        'user.name',
        'user.email',
        'approval_status',
    ];

    protected static string|\UnitEnum|null $navigationGroup = 'Marketing & Field Sales';

    public static function form(Schema $schema): Schema
    {
        return SgipDistributionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SgipDistributionsTable::configure($table);
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
            'index' => ListSgipDistributions::route('/'),
            'create' => CreateSgipDistribution::route('/create'),
            'edit' => EditSgipDistribution::route('/{record}/edit'),
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
