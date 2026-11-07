<?php

namespace App\Traits;

use App\Models\Company;
use App\Models\Department;
use App\Models\Designation;
use App\Services\DateFormatService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;

trait CreateContactFormTrait
{
    /**
     * Get common form fields for SalesDocument.
     */
    public static function getCreateContactFormTraitFields(): array
    {
        return [
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
                ])->columnSpan(3),

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

                ])->columnSpan(3),

            Section::make('Additional Information')
                ->description('Optional')
                ->collapsed(true)
                ->schema([
                    Grid::make(3) // ✅ Three-column layout
                        ->schema([
                            Select::make('designation_id')
                                ->options(fn () => Designation::pluck('name', 'id'))
                                ->searchable()
                                ->nullable()
                                ->label('Designation')
                                ->preload(),

                            Select::make('department_id')
                                ->options(fn () => Department::pluck('name', 'id'))
                                ->searchable()
                                ->nullable()
                                ->label('Department')
                                ->preload(),

                            DatePicker::make('birthday')
                                ->nullable()
                                ->displayFormat(fn (): string => app(DateFormatService::class)->dateFormat())
                                ->native(false)
                                ->label('Birthday'),

                        ]),

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
                                ]),
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
                        ]),
                    Grid::make(1) // ✅ Three-column layout
                        ->schema([
                            // ✅ Notes
                            Textarea::make('notes')
                                ->rows(3)
                                ->label('Additional Notes'),
                        ]),
                ])->columnSpanFull(),
        ];

    }
}
