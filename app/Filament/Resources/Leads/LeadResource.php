<?php

namespace App\Filament\Resources\Leads;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Actions\BulkApprovalAction;
use App\Filament\Resources\AccountMasters\RelationManagers\AddressesRelationManager;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\Leads\Pages\CreateLead;
use App\Filament\Resources\Leads\Pages\CustomFields;
use App\Filament\Resources\Leads\Pages\EditLead;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Leads\RelationManagers\ItemMastersRelationManager;
use App\Filament\Resources\Leads\RelationManagers\LeadActivityRelationManager;
use App\Filament\Resources\Leads\RelationManagers\LeadFollowUpRelationManager;
use App\Filament\Resources\Leads\RelationManagers\LeadNotesRelationManager;
use App\Models\ContactDetail;
use App\Models\FollowUpMedia;
use App\Models\Lead;
use App\Models\LeadCustomField;
use App\Models\LeadStatus;
use App\Models\User;
use App\Traits\AddressDetailsTrait;
use App\Traits\HasCustomerInteractionFields;
use App\Traits\HasSafeGlobalSearch;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class LeadResource extends BaseResource
{
    use AddressDetailsTrait;
    use HasCustomerInteractionFields;
    use HasSafeGlobalSearch;

    protected static ?string $model = Lead::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static string|\UnitEnum|null $navigationGroup = 'Marketing & Field Sales';

    protected static ?int $navigationSort = 10;

    /**
     * @var LeadStatus
     */
    protected static ?string $statusModel = LeadStatus::class;

    protected static ?string $recordTitleAttribute = 'reference_code';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->applyVisibility(static::getModelLabel());
    }

    public static function canViewAny(): bool
    {
        return true;
    }

    public static function createLead(): bool
    {
        return true;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([

                ...self::getCommonFormSchema(),

                // ✅ Address Details
                Section::make('Other Details')
                    ->collapsible()
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                Select::make('lead_source_id')
                                    ->relationship('leadSource', 'name')
                                    ->required(),

                                Select::make('rating_type_id')
                                    ->relationship('rating', 'name')
                                    ->preload(),

                                TextInput::make('annual_revenue')
                                    ->numeric()
                                    ->prefix('Rs')
                                    ->label('Annual Revenue'),

                                Textarea::make('description')
                                    ->nullable()
                                    ->label('Description Information'),
                            ])->columnSpanFull(),

                        // Dynamic Custom Fields
                        Group::make([
                            Section::make('More Details')
                                ->hidden(fn () => LeadCustomField::count() === 0)
                                ->schema(function () {
                                    return LeadCustomField::all()->map(function ($field) {
                                        return match ($field->type) {
                                            'text' => TextInput::make("custom_fields.{$field->name}")
                                                ->label($field->label)
                                                ->required(),
                                            'number' => TextInput::make("custom_fields.{$field->name}")
                                                ->numeric()
                                                ->label($field->label)
                                                ->required(),
                                            'date' => DatePicker::make("custom_fields.{$field->name}")
                                                ->label($field->label)
                                                ->required(),
                                            'email' => TextInput::make("custom_fields.{$field->name}")
                                                ->email()
                                                ->label($field->label)
                                                ->required(),
                                            default => null,
                                        };
                                    })->toArray();
                                })
                                ->columnSpanFull()
                                ->columns(2),
                        ]),

                    ])->columnSpanFull(),

            ]);

    }

    public static function mutateFormDataBeforeCreate(array $data): array
    {

        $data['custom_fields'] = collect($data['custom_fields'])
            ->filter(fn ($value) => ! is_null($value)) // Filter out empty fields
            ->toArray();

        return $data;
    }

    public static function mutateFormDataBeforeSave(array $data): array
    {

        $data['custom_fields'] = collect($data['custom_fields'])
            ->filter(fn ($value) => ! is_null($value)) // Filter out empty fields
            ->toArray();

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('owner.name')
                    ->label('Owner')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('contactDetail.full_name')
                    ->label('Contact Name')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('accountMaster.name')
                    ->label('Account Master')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn ($state, $record) => $state ?? 'No Account Master')
                    ->description(fn ($record) => $record->contactDetail && $record->accountMaster
                        ? ($record->contactDetail->accountMasters()->where('account_masters.id', $record->account_master_id)->exists()
                            ? 'Linked via Contact'
                            : 'Directly Assigned')
                        : ($record->accountMaster ? 'Directly Assigned' : null))
                    ->toggleable(),

                SelectColumn::make('status_id')
                    ->label('Status')
                    ->options(
                        LeadStatus::pluck('name', 'id')
                    )
                    ->getStateUsing(fn ($record) => $record->status_id)
                    ->afterStateUpdated(function ($record, $state) {
                        $record->update([
                            'status_id' => $state,
                            'status_type' => LeadStatus::class, // ✅ Ensure status_type is set
                        ]);

                        Notification::make()
                            ->title('Lead status updated successfully!')
                            ->success()
                            ->send();
                    }),

                TextColumn::make('followups.next_follow_up_date')
                    ->label('Next Follow-up Date')
                    ->formatStateUsing(function ($record) {
                        $nextFollowUp = $record->followups()->orderByDesc('next_follow_up_date')->first();
                        if (! $nextFollowUp || ! $nextFollowUp->next_follow_up_date) {

                            return '<a href="'.route('filament.admin.resources.follow-ups.create', ['lead_id' => $record->id]).'" class="text-blue-500 underline">Add Follow-up</a>';
                        }

                        return $nextFollowUp->next_follow_up_date;
                    })
                    ->html(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->dateTime()
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                // Dynamically add custom fields
                ...LeadCustomField::all()->map(function ($field) {
                    return TextColumn::make("custom_fields.{$field->name}")
                        ->label($field->label)
                        ->sortable()
                        ->searchable()
                        ->toggleable(isToggledHiddenByDefault: true);
                })->toArray(),

            ])
            ->filters([
                SelectFilter::make('status_id')
                    ->label('Status')
                    ->options(LeadStatus::pluck('name', 'id')->toArray())
                    ->multiple(),
                SelectFilter::make('owner_id')
                    ->label('Owner')
                    ->options(User::pluck('name', 'id')->toArray())
                    ->multiple(),
                Filter::make('has_follow_up')
                    ->label('Has Follow-up')
                    ->query(fn ($query) => $query->whereHas('followups')),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    ApprovalAction::make(),
                    DeleteAction::make(),
                    \Filament\Actions\Action::make('convert_to_deal')
                        ->label('Convert to Deal')
                        ->icon('heroicon-o-arrow-right-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->schema([
                            Checkbox::make('create_account_master')
                                ->label('Create Account Master')
                                ->default(false)
                                ->visible(function ($get, $record) {
                                    return $record->accountMaster?->type_master_id === 8;
                                }),
                        ])
                        ->action(function ($record, array $data) {
                            $createAccountMaster = $data['create_account_master'] ?? false;
                            // Convert the lead to a deal and optionally create a Company Master
                            $deal = $record->convertToDeal(createAccountMaster: $createAccountMaster);

                            Notification::make()
                                ->title('Lead Converted')
                                ->body("Lead {$record->reference_code} has been converted to Deal {$deal->reference_code}.")
                                ->success()
                                ->send();
                        })
                        ->visible(fn ($record) => $record->status?->name !== 'Converted'),
                    \Filament\Actions\Action::make('addFollowUp')
                        ->label('Add Follow-up')
                        ->icon('heroicon-o-plus')
                        ->schema([
                            Hidden::make('user_id')
                                ->default(Auth::id()) // Automatically sets the current logged-in user
                                ->required(),

                            Hidden::make('lead_id')
                                ->default(fn ($record) => $record->id),

                            DateTimePicker::make('follow_up_date')
                                ->required()
                                ->label('Follow-up Date'),

                            Select::make('to_whom')
                                ->options(function (callable $get) {
                                    $lead = Lead::find($get('lead_id'));

                                    if ($lead) {
                                        return ContactDetail::where('company_id', $lead->company_id)
                                            ->get()
                                            ->mapWithKeys(fn ($contact) => [
                                                $contact->id => "{$contact->first_name} {$contact->last_name}",
                                            ]);
                                    }

                                    return [];
                                })
                                ->searchable()
                                ->preload()
                                ->label('To Whom'),

                            Textarea::make('interaction')
                                ->label('Interaction')
                                ->rows(3)
                                ->nullable(),

                            Textarea::make('outcome')
                                ->label('Outcome')
                                ->rows(2)
                                ->nullable(),

                            Select::make('follow_up_media_id')
                                ->options(FollowUpMedia::pluck('name', 'id')->toArray())
                                ->label('Media')
                                ->nullable(),

                            DateTimePicker::make('next_follow_up_date')
                                ->label('Next Follow-up Date')
                                ->nullable(),
                        ])
                        ->action(function ($record, array $data) {
                            $record->followUps()->create($data);
                            Notification::make()
                                ->title('Follow-up added successfully!')
                                ->success()
                                ->send();
                        })
                        ->modalHeading('Add New Follow-up')
                        ->modalSubmitActionLabel('Save Follow-up')
                        ->requiresConfirmation('Are you sure you want to add this follow-up?')
                        ->modalWidth('2xl'),
                ]),
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
            AddressesRelationManager::class,
            LeadFollowUpRelationManager::class,
            ItemMastersRelationManager::class,
            LeadNotesRelationManager::class,
            LeadActivityRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeads::route('/'),
            'create' => CreateLead::route('/create'),
            'edit' => EditLead::route('/{record}/edit'),
            'custom-fields' => CustomFields::route('/custom-fields'),
        ];
    }
}
