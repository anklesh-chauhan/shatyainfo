<?php

namespace App\Traits;

use App\Models\AccountMaster;
use App\Models\ContactDetail;
use App\Models\Department;
use App\Models\Designation;
use App\Services\DateFormatService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;

trait ContactDetailsTrait
{
    /**
     * Get common form fields for SalesDocument.
     */
    public static function getContactDetailsTraitField(): array
    {
        return [
            Grid::make(2)
                ->schema([
                    Select::make('contact_detail_id')
                        ->label('Contact')
                        ->options(function (callable $get) {
                            $accountMasterId = $get('account_master_id');

                            $query = ContactDetail::query();

                            if ($accountMasterId) {
                                $query->whereHas('accountMasters', fn ($q) => $q->where('account_masters.id', $accountMasterId)
                                );
                            }

                            return $query->get()
                                ->mapWithKeys(fn ($contact) => [
                                    $contact->id => "{$contact->full_name} — ".($contact->accountMasters?->first()->name ?? 'No Account'),
                                ])
                                ->toArray();
                        })
                        ->getSearchResultsUsing(function (string $search, callable $get) {
                            $accountMasterId = $get('account_master_id');
                            $query = ContactDetail::query();

                            if ($accountMasterId) {
                                $query->whereHas('accountMasters', fn ($q) => $q->where('account_masters.id', $accountMasterId)
                                );
                            }

                            $query->where(function ($query) use ($search) {
                                $query->where('first_name', 'like', "%{$search}%")
                                    ->orWhere('last_name', 'like', "%{$search}%")
                                    ->orWhereHas('accountMasters', fn ($q) => $q->where('name', 'like', "%{$search}%")
                                    );
                            });

                            return $query->get()
                                ->mapWithKeys(fn ($contact) => [
                                    $contact->id => "{$contact->full_name} — ".($contact->accountMasters?->first()->name ?? 'No Account'),
                                ]);
                        })
                        ->getOptionLabelUsing(fn ($value) => ($contact = ContactDetail::find($value))
                                ? "{$contact->full_name} — ".($contact->accountMasters?->first()->name ?? 'No Account')
                                : 'Unknown Contact'
                        )
                        ->searchable()
                        ->preload()
                        ->nullable()
                        ->live()
                        ->createOptionForm([
                            Grid::make(3)->schema([
                                Select::make('salutation')->label('Salutation')->options([
                                    'Mr.' => 'Mr.', 'Mrs.' => 'Mrs.', 'Ms.' => 'Ms.', 'Dr.' => 'Dr.', 'Prof.' => 'Prof.',
                                ])->nullable(),
                                TextInput::make('first_name')->required(),
                                TextInput::make('last_name')->nullable(),
                            ]),
                            Grid::make(3)->schema([
                                TextInput::make('email')->email()->required(),
                                TextInput::make('mobile_number')->required()->label('Primary Phone')
                                    ->mask('+919999999999')->live(onBlur: true)->debounce(1000)
                                    ->afterStateUpdated(fn (callable $set, $state) => $set('whatsapp_number', $state)),
                                TextInput::make('alternate_phone')->mask('+919999999999')->nullable()->label('Alternate Phone'),
                            ]),
                            Grid::make(3)->schema([
                                Select::make('designation_id')
                                    ->relationship('designation', 'name')
                                    ->model(ContactDetail::class)
                                    ->searchable()
                                    ->nullable()
                                    ->label('Designation')
                                    ->createOptionForm([
                                        TextInput::make('name')
                                            ->required()
                                            ->label('New Designation'),
                                    ])
                                    ->createOptionUsing(fn (array $data) => Designation::create($data)->id)
                                    ->preload()
                                    ->default(fn (callable $get) => ContactDetail::find($get('contact_detail_id'))?->designation_id),

                                Select::make('department_id')
                                    ->relationship('department', 'name')
                                    ->model(ContactDetail::class)
                                    ->searchable()
                                    ->nullable()
                                    ->label('Department')
                                    ->createOptionForm([
                                        TextInput::make('name')
                                            ->required()
                                            ->label('New Department'),
                                    ])
                                    ->createOptionUsing(fn (array $data) => Department::create($data)->id)
                                    ->preload()
                                    ->default(fn (callable $get) => ContactDetail::find($get('contact_detail_id'))?->department_id),

                                DatePicker::make('birthday')->nullable()->displayFormat(fn (): string => app(DateFormatService::class)->dateFormat())->native(false)->label('Birthday'),
                            ]),
                            Grid::make(4)->schema([
                                TextInput::make('linkedin')->url()->label('LinkedIn'),
                                TextInput::make('facebook')->url()->label('Facebook'),
                                TextInput::make('twitter')->url()->label('Twitter'),
                                TextInput::make('website')->url()->label('Website'),
                            ]),
                        ])
                        ->createOptionUsing(function (array $data, callable $set, callable $get) {
                            $contact = ContactDetail::create($data);

                            // Attach to account master if account_master_id exists
                            if ($accountMasterId = $get('account_master_id')) {
                                $contact->accountMasters()->syncWithoutDetaching([$accountMasterId]);
                            }

                            $set('contact_id', $contact->id);

                            return $contact->id;
                        })
                        ->createOptionAction(fn (Action $action) => $action->hidden(fn (callable $get) => $get('contact_detail_id') !== null)
                        )
                        ->suffixAction(
                            Action::make('editContact')
                                ->icon('heroicon-o-pencil')
                                ->modalHeading('Edit Contact')
                                ->modalSubmitActionLabel('Update Contact')
                                ->schema(fn (callable $get) => [
                                    Grid::make(2)->schema([
                                        TextInput::make('first_name')
                                            ->default(ContactDetail::find($get('contact_detail_id'))?->first_name)
                                            ->required(),
                                        TextInput::make('last_name')
                                            ->default(ContactDetail::find($get('contact_detail_id'))?->last_name)
                                            ->nullable(),
                                    ]),
                                    Grid::make(2)->schema([
                                        TextInput::make('email')->email()
                                            ->default(ContactDetail::find($get('contact_detail_id'))?->email)->required(),
                                        TextInput::make('mobile_number')->tel()
                                            ->default(ContactDetail::find($get('contact_detail_id'))?->mobile_number)
                                            ->required()->label('Primary Phone')->reactive()->debounce(1000)
                                            ->afterStateUpdated(fn (callable $set, $state) => $set('whatsapp_number', $state)),
                                    ]),
                                    Grid::make(2)->schema([
                                        Select::make('designation_id')
                                            ->relationship('designation', 'name')
                                            ->model(ContactDetail::class)
                                            ->searchable()
                                            ->nullable()
                                            ->label('Designation')
                                            ->createOptionForm([
                                                TextInput::make('name')
                                                    ->required()
                                                    ->label('New Designation'),
                                            ])
                                            ->createOptionUsing(fn (array $data) => Designation::create($data)->id)
                                            ->preload()
                                            ->default(fn (callable $get) => ContactDetail::find($get('contact_detail_id'))?->designation_id),

                                        Select::make('department_id')
                                            ->relationship('department', 'name')
                                            ->model(ContactDetail::class)
                                            ->searchable()
                                            ->nullable()
                                            ->label('Department')
                                            ->createOptionForm([
                                                TextInput::make('name')
                                                    ->required()
                                                    ->label('New Department'),
                                            ])
                                            ->createOptionUsing(fn (array $data) => Department::create($data)->id)
                                            ->preload()
                                            ->default(fn (callable $get) => ContactDetail::find($get('contact_detail_id'))?->department_id),
                                    ]),
                                    Grid::make(2)->schema([
                                        TextInput::make('linkedin')
                                            ->default(ContactDetail::find($get('contact_detail_id'))?->linkedin)->url()->label('LinkedIn'),
                                        TextInput::make('facebook')
                                            ->default(ContactDetail::find($get('contact_detail_id'))?->facebook)->url()->label('Facebook'),
                                        TextInput::make('twitter')
                                            ->default(ContactDetail::find($get('contact_detail_id'))?->twitter)->url()->label('Twitter'),
                                        TextInput::make('website')
                                            ->default(ContactDetail::find($get('contact_detail_id'))?->website)->url()->label('Website'),
                                    ]),
                                ])
                                ->action(function (array $data, callable $get) {
                                    $contact = ContactDetail::find($get('contact_detail_id'));
                                    if ($contact) {
                                        $contact->update($data);
                                        Notification::make()->title('Contact Updated')->success()->send();
                                    }
                                })
                                ->requiresConfirmation()
                                ->visible(fn (callable $get) => $get('contact_detail_id'))
                        )
                        ->afterStateUpdated(function (callable $set, callable $get, $state) {
                            if ($state && $contact = ContactDetail::with('accountMasters')->find($state)) {
                                $set('show_contact_info', $state);
                                $set('contact_id', $state);

                                // Attach to account master if account_master_id exists
                                if ($accountMasterId = $get('account_master_id')) {
                                    $contact->accountMasters()->syncWithoutDetaching([$accountMasterId]);
                                }

                                // Set the first related account master in the account_master_id field
                                $firstAccount = $contact->accountMasters()->first();
                                $set('account_master_id', $firstAccount ? $firstAccount->id : null);

                                // Set the first related address
                                $set('address_id', $contact->addresses->first()?->id);
                            } else {
                                // Clear account_master_id and other fields if no contact is selected
                                $set('account_master_id', null);
                                $set('show_contact_info', null);
                                $set('contact_id', null);
                                $set('address_id', null);

                            }
                        })
                        ->afterStateHydrated(function (callable $set, callable $get, $state, $component) {
                            // Set contact-related fields
                            $set('show_contact_info', $state);
                            $set('contact_id', $state);

                            // Load the contact and its account masters
                            if ($state && $contact = ContactDetail::with('accountMasters')->find($state)) {
                                // Get the existing account_master_id from the form or model
                                $existingAccountMasterId = $get('account_master_id') ?? $component->getModelInstance()->account_master_id;

                                // Set the account_master_id to the existing value or the first related account master
                                if ($existingAccountMasterId && $contact->accountMasters()->where('account_masters.id', $existingAccountMasterId)->exists()) {
                                    $set('account_master_id', $existingAccountMasterId);
                                } else {
                                    $firstAccount = $contact->accountMasters()->first();
                                    $set('account_master_id', $firstAccount ? $firstAccount->id : null);
                                }

                                // Set the first related address
                                $set('address_id', $contact->addresses->first()?->id);
                            } elseif ($accountMasterId = $get('account_master_id')) {
                                // If no contact but an account master exists, try to set a contact
                                $firstContact = AccountMaster::find($accountMasterId)?->contactDetails()->first();
                                if ($firstContact) {
                                    $set('contact_detail_id', $firstContact->id);
                                    $set('show_contact_info', $firstContact->id);
                                    $set('contact_id', $firstContact->id);
                                    $set('company_id', $firstContact->company_id);
                                    $set('address_id', $firstContact->addresses->first()?->id);
                                }
                            }
                        }),

                    Placeholder::make('Contact Information')
                        ->hidden(fn (callable $get) => ! $get('show_contact_info'))
                        ->content(function (callable $get) {
                            $contact = ContactDetail::find($get('contact_detail_id'));
                            $account = $contact?->accountMasters->first();
                            $address = $contact?->addresses->first();

                            return $contact
                                ? "👤 {$contact->first_name} {$contact->last_name}
                        📧 {$contact->email}
                        📱 {$contact->mobile_number}
                        🏢 ".($account?->name ?? 'No Account')
                                : 'No contact selected.';
                        }),

                ])->columnSpanFull(),

        ];
    }
}
