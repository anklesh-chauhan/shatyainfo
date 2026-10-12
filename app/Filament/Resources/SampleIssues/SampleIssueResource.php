<?php

namespace App\Filament\Resources\SampleIssues;

use App\Filament\Resources\BaseResource;
use App\Filament\Resources\SampleIssues\Pages\CreateSampleIssue;
use App\Filament\Resources\SampleIssues\Pages\EditSampleIssue;
use App\Filament\Resources\SampleIssues\Pages\ListSampleIssues;
use App\Filament\Resources\SampleIssues\Schemas\SampleIssueForm;
use App\Filament\Resources\SampleIssues\Tables\SampleIssuesTable;
use App\Models\SampleIssue;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class SampleIssueResource extends BaseResource
{
    protected static ?string $model = SampleIssue::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static string|\UnitEnum|null $navigationGroup = 'Items & Inventory';

    protected static ?string $navigationLabel = 'Sample Issues';

    protected static ?int $navigationSort = 194;

    public static function form(Schema $schema): Schema
    {
        return SampleIssueForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SampleIssuesTable::configure($table);
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
            'index' => ListSampleIssues::route('/'),
            'create' => CreateSampleIssue::route('/create'),
            'edit' => EditSampleIssue::route('/{record}/edit'),
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
