<?php

namespace App\Filament\Resources\Companies;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Actions\BulkApprovalAction;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\Companies\Pages\CreateCompany;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Models\CityPinCode;
use App\Models\Company;
use App\Traits\HasSafeGlobalSearch;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CompanyResource extends BaseResource
{
    use HasSafeGlobalSearch;

    protected static ?string $model = Company::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Contacts & Companies';

    protected static ?string $navigationParentItem = 'Contacts';

    protected static ?string $navigationLabel = 'Companies';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 60;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('phone_number')
                    ->tel()
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255),
                TextInput::make('secondary_email')
                    ->email()
                    ->required()
                    ->maxLength(255),
                TextInput::make('website')
                    ->maxLength(255),
                TextInput::make('no_of_employees')
                    ->maxLength(255),
                TextInput::make('twitter')
                    ->maxLength(255),
                TextInput::make('linked_in')
                    ->maxLength(255),
                Textarea::make('description')
                    ->columnSpanFull(),
                Select::make('industry_type_id')
                    ->relationship('industryType', 'name')
                    ->searchable()
                    ->nullable()
                    ->label('Industry Type')
                    ->preload(), // Preload data for faster search

                // 🔄 Add Address Repeater
                Repeater::make('addresses')
                    ->relationship('addresses')
                    ->schema([
                        Hidden::make('address_type')->default('Company'),

                        TextInput::make('street')
                            ->required(),

                        // 🔍 Pin Code (Auto-fills fields only when changed)
                        TextInput::make('pin_code')
                            ->reactive()
                            ->afterStateUpdated(function (callable $set, callable $get, $state) {
                                if (! $get('city_id')) { // Only auto-fill if city is NOT set
                                    $pinCodeDetails = CityPinCode::where('pin_code', $state)->first();

                                    if ($pinCodeDetails) {
                                        $set('area_town', $pinCodeDetails->area_town);
                                        $set('city_id', $pinCodeDetails->city_id);
                                        $set('state_id', $pinCodeDetails->state_id);
                                        $set('country_id', $pinCodeDetails->country_id);
                                    }
                                }
                            }),

                        // 🔍 City (Auto-fills fields only when changed)
                        Select::make('city_id')
                            ->relationship('city', 'name')
                            ->searchable()
                            ->reactive()
                            ->afterStateUpdated(function (callable $set, callable $get, $state) {
                                if (! $get('pin_code')) { // Only auto-fill if pin_code is NOT set
                                    $pinCodeDetails = CityPinCode::where('city_id', $state)->first();

                                    if ($pinCodeDetails) {
                                        $set('area_town', $pinCodeDetails->area_town);
                                        $set('pin_code', $pinCodeDetails->pin_code);
                                        $set('state_id', $pinCodeDetails->state_id);
                                        $set('country_id', $pinCodeDetails->country_id);
                                    }
                                }
                            }),

                        // 🔍 Area/Town (Save as a string only)
                        TextInput::make('area_town')
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(function (callable $set, callable $get, $state) {
                                // Area/Town change will NOT affect pin_code, city, etc.
                                $set('area_town', $state); // Save the entered value directly
                            }),

                        Select::make('state_id')
                            ->relationship('state', 'name')->searchable(),

                        Select::make('country_id')
                            ->relationship('country', 'name')->searchable(),
                    ])
                    ->collapsible() // Optional for better UI
                    ->orderColumn() // Enables drag & drop sorting
                    ->addActionLabel('Add Address'), // Custom add button text
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('phone_number')
                    ->searchable(),
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('secondary_email')
                    ->searchable(),
                TextColumn::make('website')
                    ->searchable(),
                TextColumn::make('no_of_employees')
                    ->searchable(),
                TextColumn::make('twitter')
                    ->searchable(),
                TextColumn::make('linked_in')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                ApprovalAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([

                    BulkApprovalAction::make(),

                    DeleteBulkAction::make(),
                ]),
            ]);
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
            'index' => ListCompanies::route('/'),
            'create' => CreateCompany::route('/create'),
            'edit' => EditCompany::route('/{record}/edit'),
        ];
    }
}
