<?php

namespace App\Filament\Resources\ContactDetails;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\ContactDetails\Pages\CreateContactDetail;
use App\Filament\Resources\ContactDetails\Pages\EditContactDetail;
use App\Filament\Resources\ContactDetails\Pages\ListContactDetails;
use App\Models\CityPinCode;
use App\Models\Company;
use App\Models\ContactDetail;
use App\Models\Department;
use App\Models\Designation;
use App\Traits\HasSafeGlobalSearch;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ContactDetailResource extends BaseResource
{
    use HasSafeGlobalSearch;

    protected static ?string $model = ContactDetail::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Contacts & Companies';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $navigationLabel = 'Contacts';

    protected static ?int $navigationSort = 50;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(3) // ✅ Three-column layout
                    ->schema([
                        // ✅ Salutation
                        Select::make('salutation')
                            ->options([
                                'Mr.' => 'Mr.',
                                'Mrs.' => 'Mrs.',
                                'Ms.' => 'Ms.',
                                'Dr.' => 'Dr.',
                                'Prof.' => 'Prof.',
                                'Er.' => 'Er.',
                                'Other' => 'Other',
                            ])
                            ->nullable()
                            ->columnSpan(1),

                        // ✅ Contact Information
                        TextInput::make('first_name')
                            ->required()
                            ->label('First Name')
                            ->columnSpan(1),

                        TextInput::make('last_name')
                            ->label('Last Name')
                            ->columnSpan(1),
                    ])->columnSpanFull(),
                Grid::make(3) // ✅ Three-column layout
                    ->schema([
                        TextInput::make('email')
                            ->email()
                            ->required(),

                        TextInput::make('mobile_number')
                            ->tel()
                            ->required()
                            ->label('Primary Phone')
                            ->reactive() // ✅ Enables live updates
                            ->debounce(1000)
                            ->afterStateUpdated(fn (callable $set, $state) => $set('whatsapp_number', $state)),

                        TextInput::make('alternate_phone')
                            ->tel()
                            ->label('Alternate Phone'),

                    ])->columnSpanFull(),
                Grid::make(3) // ✅ Three-column layout
                    ->schema([
                        Select::make('designation_id')
                            ->label('Designation')
                            ->options(function () {
                                return Designation::pluck('name', 'id')->toArray();
                            })
                            ->searchable()
                            ->nullable()
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->required()
                                    ->label('New Designation'),
                            ])
                            ->createOptionUsing(function (array $data) {
                                return Designation::create($data)->id;
                            })
                            ->preload(),

                        Select::make('department_id')
                            ->options(function () {
                                return Department::pluck('name', 'id')->toArray();
                            })
                            ->searchable()
                            ->nullable()
                            ->label('Department')
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->required()
                                    ->label('New Department'),
                            ])
                            ->createOptionUsing(function (array $data) {
                                return Department::create($data)->id;  // ✅ Create and return ID
                            })->preload(),
                        DatePicker::make('birthday')
                            ->nullable()
                            ->displayFormat('d M Y')
                            ->native(false)
                            ->label('Birthday'),

                    ])->columnSpanFull(),

                Select::make('company_id')
                    ->relationship('company', 'name')
                    ->searchable()
                    ->nullable()
                    ->reactive() // ✅ Enables dynamic updates
                    ->afterStateHydrated(function (callable $set, callable $get, $state) {
                        // ✅ Ensure `company_id` persists after new company creation
                        if ($state) {
                            $set('addresses', collect($get('addresses') ?? [])->map(function ($address) use ($state) {
                                $address['company_id'] = $state;
                                $address['address_type'] = $state ? 'Company' : ($address['address_type'] ?? 'Other');

                                return $address;
                            })->toArray());
                        }
                    })
                    ->afterStateUpdated(function (callable $set, callable $get, $state) {
                        if ($state) {
                            $company = Company::with('addresses')->find($state);

                            if ($company && $company->addresses->isNotEmpty()) {
                                $companyAddress = $company->addresses->first();

                                // ✅ Auto-fill company address with `company_id`
                                $set('addresses', [
                                    [
                                        'street' => $companyAddress->street,
                                        'area_town' => $companyAddress->area_town,
                                        'pin_code' => $companyAddress->pin_code,
                                        'city_id' => $companyAddress->city_id,
                                        'state_id' => $companyAddress->state_id,
                                        'country_id' => $companyAddress->country_id,
                                        'address_type' => 'Company',
                                        'company_id' => $company->id, // ✅ Inject company_id directly
                                    ],
                                ]);
                            }
                        }
                    })
                    ->label('Company (Optional)')
                    ->createOptionForm([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('name')
                                    ->required()
                                    ->label('Company Name'),

                                TextInput::make('email')
                                    ->email()
                                    ->nullable()
                                    ->label('Company Email'),

                                TextInput::make('website')
                                    ->url()
                                    ->nullable()
                                    ->label('Website'),

                                Select::make('industry_type_id')
                                    ->relationship('industryType', 'name')
                                    ->searchable()
                                    ->nullable()
                                    ->label('Industry Type')
                                    ->preload(),

                                TextInput::make('no_of_employees')
                                    ->maxLength(255),

                                Textarea::make('description')
                                    ->nullable()
                                    ->label('Company Description'),
                            ])->columnSpanFull(),
                    ])
                    ->createOptionUsing(function (array $data, callable $set, callable $get) {
                        $company = Company::create($data);

                        // ✅ Force `.afterStateUpdated()` to run and apply logic
                        $set('company_id', $company->id);

                        // ✅ Inject newly created `company_id` into addresses array
                        $set('addresses', collect($get('addresses') ?? [])->map(function ($address) use ($company) {
                            $address['company_id'] = $company->id;
                            $address['address_type'] = 'Company';

                            return $address;
                        })->toArray());

                        return $company->id; // ✅ Return the new company ID
                    })
                    ->preload(), // ✅ Preload data for faster search

                TextInput::make('whatsapp_number')
                    ->tel()
                    ->label('WhatsApp Number')
                    ->placeholder('Same as phone number unless changed'),

                Grid::make(4) // ✅ Three-column layout
                    ->schema([
                        // ✅ Social Media
                        TextInput::make('linkedin')->url()->label('LinkedIn'),
                        TextInput::make('facebook')->url()->label('Facebook'),
                        TextInput::make('twitter')->url()->label('Twitter'),
                        TextInput::make('website')->url()->label('Website'),
                    ])->columnSpanFull(),
                Grid::make(1) // ✅ Three-column layout
                    ->schema([
                        // ✅ Notes
                        Textarea::make('notes')
                            ->rows(3)
                            ->label('Additional Notes'),
                    ])->columnSpanFull(),

                Grid::make(1) // ✅ Three-column layout
                    ->schema([

                        // 🔄 Add Address Repeater
                        Repeater::make('addresses')
                            ->relationship('addresses')
                            ->schema([
                                Grid::make(3) // ✅ Three-column layout
                                    ->schema([

                                        Hidden::make('company_id')
                                            ->default(fn (callable $get) => $get('company_id')) // ✅ Auto-set when creating new records
                                            ->dehydrated(),

                                        Select::make('address_type')
                                            ->options([
                                                'Company' => 'Company',
                                                'Home' => 'Home',
                                                'Office' => 'Office',
                                                'Other' => 'Other',
                                            ])
                                            ->required()
                                            ->label('Address Type'),

                                        TextInput::make('street')->required(),
                                        TextInput::make('area_town')->required(),

                                        TextInput::make('pin_code')
                                            ->reactive()
                                            ->afterStateUpdated(function (callable $set, callable $get, $state) {
                                                if (! $get('city_id')) {
                                                    $pinCodeDetails = CityPinCode::where('pin_code', $state)->first();
                                                    if ($pinCodeDetails) {
                                                        $set('area_town', $pinCodeDetails->area_town);
                                                        $set('city_id', $pinCodeDetails->city_id);
                                                        $set('state_id', $pinCodeDetails->state_id);
                                                        $set('country_id', $pinCodeDetails->country_id);
                                                    }
                                                }
                                            }),

                                        Select::make('city_id')
                                            ->relationship('city', 'name')
                                            ->searchable(),

                                        Select::make('state_id')
                                            ->relationship('state', 'name')
                                            ->searchable(),

                                        Select::make('country_id')
                                            ->relationship('country', 'name')
                                            ->searchable(),
                                    ])->columnSpanFull(),
                            ])->columnSpanFull()
                            ->collapsible() // Optional for better UI
                            ->orderColumn() // Enables drag & drop sorting
                            ->addActionLabel('Add Address') // ✅ Custom add button text
                            ->default(function (callable $get) {
                                return [['company_id' => $get('company_id')]]; // ✅ Ensures `company_id` is included by default
                            })->columnSpanFull(),
                    ])->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('salutation')
                    ->sortable(),

                TextColumn::make('first_name')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('last_name')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('birthday')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('mobile_number')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('email')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('company.name')
                    ->label('Company')
                    ->sortable()
                    ->default('N/A'),
            ])
            ->filters([
                SelectFilter::make('company_id')
                    ->relationship('company', 'name')
                    ->label('Filter by Company'),
            ])
            ->recordActions([
                EditAction::make(),
                ApprovalAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContactDetails::route('/'),
            'create' => CreateContactDetail::route('/create'),
            'edit' => EditContactDetail::route('/{record}/edit'),
        ];
    }
}
