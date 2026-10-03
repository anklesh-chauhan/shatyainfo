<?php

namespace App\Filament\Resources\TravelSegments;

use App\Filament\Resources\TravelSegments\Pages\CreateTravelSegment;
use App\Filament\Resources\TravelSegments\Pages\EditTravelSegment;
use App\Filament\Resources\TravelSegments\Pages\ListTravelSegments;
use App\Filament\Resources\TravelSegments\Schemas\TravelSegmentForm;
use App\Filament\Resources\TravelSegments\Tables\TravelSegmentsTable;
use App\Models\TravelSegment;
use App\Traits\HasRelatedGlobalSearch;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TravelSegmentResource extends Resource
{
    use HasRelatedGlobalSearch;

    protected static ?string $model = TravelSegment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = null;

    protected static array $relatedGlobalSearchAttributes = [
        'fromAccount.name',
        'toAccount.name',
    ];

    protected static string|\UnitEnum|null $navigationGroup = 'Marketing & Field Sales';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Travel Segments';

    public static function form(Schema $schema): Schema
    {
        return TravelSegmentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TravelSegmentsTable::configure($table);
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
            'index' => ListTravelSegments::route('/'),
            'create' => CreateTravelSegment::route('/create'),
            'edit' => EditTravelSegment::route('/{record}/edit'),
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
