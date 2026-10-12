<?php

namespace App\Filament\Resources\GoodsReceiptNotes;

use App\Filament\Resources\BaseResource;
use App\Filament\Resources\GoodsReceiptNotes\Pages\CreateGoodsReceiptNote;
use App\Filament\Resources\GoodsReceiptNotes\Pages\EditGoodsReceiptNote;
use App\Filament\Resources\GoodsReceiptNotes\Pages\ListGoodsReceiptNotes;
use App\Filament\Resources\GoodsReceiptNotes\Schemas\GoodsReceiptNoteForm;
use App\Filament\Resources\GoodsReceiptNotes\Tables\GoodsReceiptNotesTable;
use App\Models\GoodsReceiptNote;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class GoodsReceiptNoteResource extends BaseResource
{
    protected static ?string $model = GoodsReceiptNote::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBoxArrowDown;

    protected static string|\UnitEnum|null $navigationGroup = 'Items & Inventory';

    protected static ?string $navigationLabel = 'Goods Receipt (GRN)';

    protected static ?int $navigationSort = 196;

    public static function form(Schema $schema): Schema
    {
        return GoodsReceiptNoteForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GoodsReceiptNotesTable::configure($table)
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGoodsReceiptNotes::route('/'),
            'create' => CreateGoodsReceiptNote::route('/create'),
            'edit' => EditGoodsReceiptNote::route('/{record}/edit'),
        ];
    }
}
