<?php

namespace App\Filament\Resources\SampleRequests;

use App\Filament\Resources\BaseResource;
use App\Filament\Resources\SampleRequests\Pages\CreateSampleRequest;
use App\Filament\Resources\SampleRequests\Pages\EditSampleRequest;
use App\Filament\Resources\SampleRequests\Pages\ListSampleRequests;
use App\Filament\Resources\SampleRequests\Schemas\SampleRequestForm;
use App\Filament\Resources\SampleRequests\Tables\SampleRequestsTable;
use App\Models\SampleRequest;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SampleRequestResource extends BaseResource
{
    protected static ?string $model = SampleRequest::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static string|\UnitEnum|null $navigationGroup = 'Items & Inventory';

    protected static ?string $navigationLabel = 'Sample Requests';

    protected static ?int $navigationSort = 193;

    public static function form(Schema $schema): Schema
    {
        return SampleRequestForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SampleRequestsTable::configure($table);
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
            'index' => ListSampleRequests::route('/'),
            'create' => CreateSampleRequest::route('/create'),
            'edit' => EditSampleRequest::route('/{record}/edit'),
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
