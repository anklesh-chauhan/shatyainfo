<?php

namespace App\Filament\Resources\SalesDocumentPreferences;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\SalesDocumentPreferenceResource\Pages;
use App\Filament\Resources\SalesDocumentPreferences\Pages\EditSalesDocumentPreference;
use App\Filament\Resources\SalesDocumentPreferences\Pages\ListSalesDocumentPreferences;
use App\Models\SalesDocumentPreference;
use App\Traits\HasSafeGlobalSearch;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SalesDocumentPreferenceResource extends BaseResource
{
    use HasSafeGlobalSearch;

    protected static ?string $model = SalesDocumentPreference::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|\UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('PDF Preferences')
                    ->schema([
                        Toggle::make('attach_pdf_in_email')
                            ->label('Attach PDF with email')
                            ->default(true),
                        Toggle::make('encrypt_pdf')
                            ->label('Encrypt PDF before sending'),
                    ]),

                Section::make('Discount Settings')
                    ->schema([
                        Select::make('discount_level')
                            ->options([
                                'none' => 'No Discount',
                                'line_item' => 'At Line Item Level',
                                'transaction' => 'At Transaction Level',
                                'both' => 'Both Line Item & Transaction Level',
                            ])
                            ->required(),
                    ]),

                Section::make('Additional Charges')
                    ->schema([
                        Toggle::make('include_adjustments')
                            ->label('Include Adjustments'),
                        Toggle::make('include_shipping_charges')
                            ->label('Include Shipping Charges'),
                    ]),

                Section::make('Tax Settings')
                    ->schema([
                        Select::make('tax_mode')
                            ->options([
                                'inclusive' => 'Tax Inclusive',
                                'exclusive' => 'Tax Exclusive',
                                'both' => 'Tax Inclusive or Tax Exclusive',
                            ])
                            ->required(),
                    ]),

                Section::make('Rounding Settings')
                    ->schema([
                        Select::make('rounding_option')
                            ->options([
                                'none' => 'No Rounding',
                                'nearest' => 'Round off to nearest whole number',
                            ])
                            ->required(),
                    ]),

                Section::make('Sales & Expense Settings')
                    ->schema([
                        Toggle::make('enable_salesperson')
                            ->label('Enable Salesperson Field'),
                        Toggle::make('enable_billable_expenses')
                            ->label('Enable Billable Expenses'),
                        TextInput::make('default_markup_percentage')
                            ->label('Default Markup (%)')
                            ->suffix('%')
                            ->numeric()
                            ->step(0.01),
                    ]),

                Section::make('Document Copies')
                    ->schema([
                        Select::make('document_copy_type')
                            ->label('Document Copy Format')
                            ->options([
                                'original_duplicate' => 'ORIGINAL / DUPLICATE',
                                'original_duplicate_triplicate' => 'ORIGINAL / DUPLICATE / TRIPLICATE',
                                'original_duplicate_triplicate_quadruplicate' => 'ORIGINAL / DUPLICATE / TRIPLICATE / QUADRUPLICATE',
                                'original_duplicate_triplicate_quadruplicate_quintuplicate' => 'ORIGINAL / DUPLICATE / TRIPLICATE / QUADRUPLICATE / QUINTUPLICATE',
                                'two_copies' => 'Two Copies',
                                'three_copies' => 'Three Copies',
                                'four_five_copies' => 'Four / Five Copies',
                            ])
                            ->required(),
                    ]),

                Section::make('Print Preferences')
                    ->schema([
                        Textarea::make('default_print_preferences')
                            ->label('Default Print Preferences (JSON)')
                            ->rows(4)
                            ->hint('Optional: Store advanced print options in JSON format')
                            ->placeholder('{ "copies": 2, "layout": "A4" }'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                IconColumn::make('attach_pdf_in_email')->boolean(),
                IconColumn::make('encrypt_pdf')->boolean(),
                TextColumn::make('discount_level')->label('Discount'),
                TextColumn::make('tax_mode')->label('Tax Mode'),
                TextColumn::make('rounding_option')->label('Rounding'),
                IconColumn::make('enable_salesperson')->label('Salesperson')->boolean(),
                TextColumn::make('default_markup_percentage')->suffix('%')->sortable(),
            ])
            ->defaultSort('id', 'asc')
            ->filters([])
            ->recordActions([
                EditAction::make(),
                ApprovalAction::make(),
            ])
            ->toolbarActions([]);

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
            'index' => ListSalesDocumentPreferences::route('/'),
            // 'create' => Pages\CreateSalesDocumentPreference::route('/create'),
            'edit' => EditSalesDocumentPreference::route('/{record}/edit'),
        ];
    }
}
