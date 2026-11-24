<?php

namespace App\Filament\Resources\GoodsReceiptNotes;

use App\Filament\Resources\BaseResource;
use App\Filament\Resources\GoodsReceiptNotes\Pages\CreateGoodsReceiptNote;
use App\Filament\Resources\GoodsReceiptNotes\Pages\EditGoodsReceiptNote;
use App\Filament\Resources\GoodsReceiptNotes\Pages\ListGoodsReceiptNotes;
use App\Filament\Resources\GoodsReceiptNotes\Schemas\GoodsReceiptNoteForm;
use App\Filament\Resources\GoodsReceiptNotes\Tables\GoodsReceiptNotesTable;
use App\Models\GoodsReceiptNote;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class GoodsReceiptNoteResource extends BaseResource
{
    protected static string|\UnitEnum|null $navigationGroup = 'Inventory & Purchasing';

    protected static ?string $model = GoodsReceiptNote::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static ?string $navigationLabel = 'Goods Receipts (GRN)';

    protected static ?int $navigationSort = 40;

    public static function canViewAny(): bool
    {
        return parent::canViewAny() && auth()->user()->can('View:GoodsReceiptNote');
    }

    public static function form(Schema $schema): Schema
    {
        return GoodsReceiptNoteForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GoodsReceiptNotesTable::configure($table);
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
