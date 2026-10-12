<?php

namespace App\Filament\Resources\Taxes;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\Taxes\Pages\CreateTax;
use App\Filament\Resources\Taxes\Pages\EditTax;
use App\Filament\Resources\Taxes\Pages\ListTaxes;
use App\Models\Tax;
use App\Traits\HasSafeGlobalSearch;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
// use Filament\Resources\Table; // Remove this incorrect import
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TaxResource extends BaseResource
{
    use HasSafeGlobalSearch;

    protected static ?string $model = Tax::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|\UnitEnum|null $navigationGroup = 'Tax Management';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(100),

                TextInput::make('total_rate')
                    ->label('Total Tax Rate (%)')
                    ->numeric()
                    ->required()
                    ->step(0.01)
                    ->suffix('%'),

                Toggle::make('is_active')->default(true),

                Repeater::make('components')
                    ->label('Tax Components')
                    ->relationship('components')
                    ->schema([
                        Select::make('type')
                            ->options([
                                'CGST' => 'CGST',
                                'SGST' => 'SGST',
                                'IGST' => 'IGST',
                                'CESS' => 'CESS',
                                'VAT' => 'VAT',
                                'EXCISE' => 'EXCISE',
                                'CUSTOM' => 'CUSTOM',
                            ])
                            ->required(),

                        TextInput::make('rate')
                            ->numeric()
                            ->required()
                            ->step(0.01)
                            ->suffix('%'),
                    ])
                    ->defaultItems(1)
                    ->columns(3)
                    ->columnSpanFull()
                    ->reorderable()
                    ->addActionLabel('Add Component'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('total_rate')->label('Rate (%)')->sortable(),
                TextColumn::make('supply_type')->label('Type'),
                TextColumn::make('is_active')
                    ->badge('Active', 'Inactive')
                    ->colors(['success' => true, 'danger' => false]),
                TextColumn::make('is_default')
                    ->badge('Default', 'Not Default')
                    ->colors(['primary' => true]),
            ])
            ->defaultSort('name')
            ->filters([])
            ->recordActions([
                EditAction::make(),
                ApprovalAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return []; // No separate relation managers needed
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTaxes::route('/'),
            'create' => CreateTax::route('/create'),
            'edit' => EditTax::route('/{record}/edit'),
        ];
    }
}
