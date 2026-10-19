<?php

namespace App\Traits;

use App\Models\AccountMaster;
use App\Models\TypeMaster;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Auth;

trait CreateAccountMasterTrait
{
    /**
     * Get common form fields for SalesDocument.
     */
    public static function getCreateAccountMasterTraitFields(): array
    {
        return [
            Grid::make(3)
                ->schema([
                    Select::make('owner_id')
                        ->relationship('owner', 'name')
                        ->default(fn () => Auth::id())
                        ->required()
                        ->label('Owner'),

                    Select::make('parent_type_id')
                        ->label('Account Type')->dehydrated(false)
                        ->searchable()
                        ->options(
                            TypeMaster::whereNull('parent_id')
                                ->where('typeable_type', AccountMaster::class)
                                ->pluck('name', 'id')
                        )
                        ->required()
                        ->live()
                        ->afterStateHydrated(function (callable $set, callable $get) {

                            $typeMasterId = $get('type_master_id');

                            if (! $typeMasterId) {
                                return;
                            }

                            $type = TypeMaster::find($typeMasterId);

                            // If subtype → set its parent
                            if ($type?->parent_id) {
                                $set('parent_type_id', $type->parent_id);
                            } else {
                                // Parent-only type
                                $set('parent_type_id', $typeMasterId);
                            }
                        })
                        ->afterStateUpdated(function (callable $set, callable $get, $state) {

                            $hasChildren = TypeMaster::where('parent_id', $state)->exists();

                            if (! $hasChildren) {
                                $set('type_master_id', $state);

                            } else {
                                $set('type_master_id', null);
                            }
                        }),

                    Select::make('type_master_id')
                        ->label('Account Sub Type')
                        ->searchable()
                        ->options(fn (Get $get) => TypeMaster::where('parent_id', $get('parent_type_id'))
                            ->pluck('name', 'id')
                        )
                        ->visible(fn (Get $get) => filled($get('parent_type_id')) &&
                            TypeMaster::where('parent_id', $get('parent_type_id'))->exists()
                        )
                        ->required(fn (Get $get) => TypeMaster::where('parent_id', $get('parent_type_id'))->exists()
                        )
                        ->live(),

                    TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('external_reference')
                        ->label('External Reference')
                        ->maxLength(128)
                        ->regex('/^[A-Za-z0-9][A-Za-z0-9_.:\/-]*$/')
                        ->dehydrateStateUsing(fn ($state) => filled($state) ? strtoupper(trim($state)) : null)
                        ->unique(ignoreRecord: true)
                        ->disabled(fn ($record): bool => filled($record?->external_reference))
                        ->helperText('Stable import ID, for example ERP:000123. Set once; account imports match this reference.'),
                    TextInput::make('account_code')
                        ->disabled() // Prevent manual edits
                        ->maxLength(255)
                        ->live() // Make it reactive to reflect updates
                        ->dehydrated(false) // Prevent sending initial default to server
                        ->placeholder('Assigned on save')->default(''),

                    TextInput::make('phone_number')
                        ->tel()
                        ->maxLength(255),
                    TextInput::make('email')
                        ->email()
                        ->maxLength(255),
                    TextInput::make('secondary_email')
                        ->email()
                        ->maxLength(255),
                    TextInput::make('website')
                        ->url()
                        ->maxLength(255),
                    TextInput::make('no_of_employees')
                        ->maxLength(255),
                    TextInput::make('twitter')
                        ->url()
                        ->maxLength(255),
                    TextInput::make('linked_in')
                        ->url()
                        ->maxLength(255),
                    TextInput::make('annual_revenue')
                        ->maxLength(255),
                    TextInput::make('sic_code')
                        ->maxLength(255),
                    TextInput::make('ticker_symbol')
                        ->maxLength(255),
                    Textarea::make('description')
                        ->columnSpanFull(),
                    Select::make('industry_type_id')
                        ->relationship('industryType', 'name')
                        ->searchable()
                        ->preload(),

                    Select::make('ref_dealer_contact')
                        ->label('Ref Contact')
                        ->relationship('dealerName', 'id') // Use 'id' here for relationship
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->FullName),
                    TextInput::make('commission')
                        ->numeric()
                        ->minValue(0)->maxValue(100)->suffix('%'),
                    TextInput::make('alias')
                        ->maxLength(255),

                    Select::make('account_status_id')->label('Lifecycle Status')->relationship('accountStatus', 'name')->searchable()->preload(),
                    Select::make('rating_type_id')
                        ->label('Potential Rating')->relationship('ratingType', 'name', modifyQueryUsing: fn ($query) => $query->where('is_lifecycle', false))
                        ->searchable()
                        ->preload(),

                    Select::make('territory_id')
                        ->relationship('territory', 'name')
                        ->searchable()
                        ->disabled(fn () => ! auth()->user()?->hasRole('super_admin'))
                        ->hint('Auto Suggested')
                        ->preload(),

                    Select::make('specialty_id')->label('Primary Specialty')->relationship('specialty', 'name', modifyQueryUsing: fn ($query) => $query->where('kind', 'specialty')->where('is_active', true))->searchable()->preload(),
                    Select::make('therapy_area_id')->label('Primary Therapy Area')->relationship('therapyArea', 'name', modifyQueryUsing: fn ($query) => $query->where('kind', 'therapy_area')->where('is_active', true))->searchable()->preload(),
                    Select::make('institution_account_id')->label('Primary Institution')->relationship('institution', 'name', modifyQueryUsing: fn ($query, $record) => $query->applyVisibility('AccountMaster')->when($record, fn ($q) => $q->whereKeyNot($record->id)))->searchable(),
                    Select::make('supplier_account_id')->label('Primary Supplying Stockist / Distributor')->relationship('supplier', 'name', modifyQueryUsing: fn ($query, $record) => $query->applyVisibility('AccountMaster')->when($record, fn ($q) => $q->whereKeyNot($record->id)))->searchable(),
                    Select::make('patches')->relationship('patches', 'name')->multiple()->searchable()->preload()->helperText('Changes are recorded in assignment history.'),
                    Select::make('parent_id')
                        ->relationship('parent', 'name')
                        ->label('Parent Account')->helperText('Use the separate Institution and Supplier fields for reporting.')
                        ->searchable()
                        ->preload(),

                ])->columnSpanFull(),
        ];

    }
}
