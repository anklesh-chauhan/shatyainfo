<?php

namespace App\Filament\Resources\AccountMasters\RelationManagers;

use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\AttachAction;
use Filament\Forms\Components\Select;
use App\Models\ContactDetail;
use Filament\Forms\Components\Placeholder;
use Filament\Actions\CreateAction;
use App\Models\Company;
use Filament\Actions\EditAction;
use Filament\Actions\DetachAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\DeleteBulkAction;
use App\Traits\CreateContactFormTrait;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ContactDetailsRelationManager extends RelationManager
{
    use CreateContactFormTrait;

    protected static string $relationship = 'contactDetails';

    protected $listeners = ['close-attached-modal' => 'closeAttachModal'];

    public function form(Schema $schema): Schema
    {
        return $schema->components(
            self::getCreateContactFormTraitFields()
        );
    }

    public function closeAttachModal()
    {
        $this->dispatch('closeModal');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('full_name')
            ->columns([
                TextColumn::make('full_name')
                    ->label('Name')
                    ->sortable(),
                TextColumn::make('email')
                    ->sortable()
                    ->searchable(query: function ($query, string $search) {
                        $query->whereLike('first_name', "%{$search}%")
                            ->orWhereLike('last_name', "%{$search}%")
                            ->orWhereLike('email', "%{$search}%")
                            ->orWhereLike('mobile_number', "%{$search}%")
                            ->orWhereHas('company', fn ($q) =>
                                $q->whereLike('name', "%{$search}%")
                            );
                    }),
                TextColumn::make('mobile_number')
                    ->label('Phone')
                    ->sortable()
                    ->searchable(),
            ])
            ->filters([
                SelectFilter::make('company')
                    ->relationship('company', 'name')
                    ->preload(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Attach Contact')
                    ->closeModalByClickingAway(false)
                    ->form(function (AttachAction $action): array {
                        return [
                            Select::make('recordId')
                                ->label('Contact')
                                ->options(function () {
                                    return ContactDetail::query()
                                        ->get()
                                        ->mapWithKeys(fn ($contact) => [
                                            $contact->id => "{$contact->full_name} — " . ($contact->company?->name ?? 'No Company')
                                        ])
                                        ->toArray();
                                })
                                ->searchable()
                                ->getSearchResultsUsing(function (string $search) {
                                    return ContactDetail::query()
                                        ->where(function ($query) use ($search) {
                                            // Search across first_name and last_name instead of a single full_name column
                                            $query->whereLike('first_name', "%{$search}%")
                                                ->orWhereLike('last_name', "%{$search}%")
                                                ->orWhereLike('email', "%{$search}%")
                                                ->orWhereLike('mobile_number', "%{$search}%")
                                                ->orWhereHas('company', fn ($q) => $q->whereLike('name', "%{$search}%"));
                                        })
                                        ->limit(50)
                                        ->get()
                                        ->mapWithKeys(fn ($contact) => [
                                            $contact->id => "{$contact->full_name} — " . ($contact->company?->name ?? 'No Company')
                                        ]);
                                })
                                ->getOptionLabelUsing(fn ($value) =>
                                    ($contact = ContactDetail::find($value))
                                        ? "{$contact->full_name} — " . ($contact->company?->name ?? 'No Company')
                                        : 'Unknown Contact'
                                )
                                ->preload()
                                ->live()
                                ->helperText('Search for a contact. If not found, use the "Create New Contact" action below.')
                                ->required(),
                            Placeholder::make('create_info')
                                ->content('Can’t find the contact? Create a new one below.'),
                        ];
                    })
                    ->action(function (array $data, RelationManager $livewire) {
                        $contactId = $data['recordId'];
                        $livewire->ownerRecord->contactDetails()->attach($contactId);
                        Notification::make()
                            ->title('Contact Attached')
                            ->success()
                            ->send();
                    })
                    ->extraModalFooterActions(function (AttachAction $action) {
                        return [
                            CreateAction::make()
                                ->label('Create New Contact')
                                ->icon('heroicon-o-plus')
                                ->modalWidth('4xl')
                                ->schema(self::getCreateContactFormTraitFields())
                                ->createAnother(false)
                                ->mutateDataUsing(function (array $data) {
                                    return $data; // Optional: Transform data if needed
                                })
                                ->action(function (array $data, RelationManager $livewire) {
                                    // Create the contact manually
                                    $contact = ContactDetail::create($data);

                                    // Attach it to the current AccountMaster
                                    $livewire->ownerRecord->contactDetails()->attach($contact->id);

                                    // Attach to company if exists
                                    $company = Company::where('account_master_id', $livewire->ownerRecord->id)->first();
                                    if ($company) {
                                        $contact->company_id = $company->id;
                                        $contact->save();
                                    }

                                    Notification::make()
                                        ->title('Contact Created and Attached')
                                        ->success()
                                        ->send();

                                    // Close modal manually
                                    $livewire->dispatch('close-modal'); // Close inner "Create Contact"
                                    $livewire->dispatch('close-attached-modal'); // Custom event to close parent
                                }),
                        ];
                    }),

                CreateAction::make()
                    ->after(function (RelationManager $livewire, $record) {
                        $accountMaster = $livewire->getOwnerRecord();
                        $company = Company::where('account_master_id', $accountMaster->id)->first();

                        if ($company) {
                            $record->company_id = $company->id;
                            $record->save();

                            Notification::make()
                                ->title('Contact linked to Company')
                                ->success()
                                ->send();
                        }
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                DetachAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
