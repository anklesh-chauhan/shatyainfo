<?php

namespace App\Filament\Resources\ItemMasters\RelationManagers;

use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\AttachAction;
use Filament\Forms\Components\Select;
use App\Models\AccountMaster;
use Filament\Forms\Components\Placeholder;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Traits\CreateAccountMasterTrait;
use Filament\Notifications\Notification;

class SuppliersRelationManager extends RelationManager
{
    use CreateAccountMasterTrait;

    protected static string $relationship = 'suppliers';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                ...self::getCreateAccountMasterTraitFields(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Attach Supplier')
                    ->closeModalByClickingAway(false)
                    ->form(function (AttachAction $action): array {
                        return [
                            Select::make('recordId')
                                ->label('Supplier')
                                ->options(function () {
                                    return AccountMaster::query()
                                        ->get()
                                        ->mapWithKeys(fn ($account_master) => [
                                            $account_master->id => "{$account_master->name} — " . ($account_master->account_code ?? 'No Account Code')
                                        ])
                                        ->toArray();
                                })
                                ->searchable()
                                ->getSearchResultsUsing(function (string $search) {
                                    return AccountMaster::query()
                                        ->where(function ($query) use ($search) {
                                            $query->whereLike('name', "%{$search}%")
                                                ->orWhereLike('account_code', "%{$search}%")
                                                ->orWhereLike('email', "%{$search}%")
                                                ->orWhereLike('phone_number', "%{$search}%");
                                        })
                                        ->get()
                                        ->mapWithKeys(fn ($account_master) => [
                                            $account_master->id => "{$account_master->name} — " . ($account_master->account_code ?? 'No Account Code')
                                        ]);
                                })
                                ->getOptionLabelUsing(fn ($value) =>
                                    ($account_master = AccountMaster::find($value))
                                        ? "{$account_master->name} — " . ($account_master->account_code ?? 'No Account Code')
                                        : 'Unknown Supplier'
                                )
                                ->preload()
                                ->live()
                                ->helperText('Search for a Supplier. If not found, use the "Create New Supplier" action below.')
                                ->required(),
                            Placeholder::make('create_info')
                                ->content('Can’t find the contact? Create a new one below.'),
                        ];
                    })
                    ->action(function (array $data, RelationManager $livewire) {
                        $suppliersID = $data['recordId'];
                        // Prevent duplicate attachments
                        if (!$livewire->ownerRecord->suppliers()->where('account_masters.id', $suppliersID)->exists()) {
                            $livewire->ownerRecord->suppliers()->attach($suppliersID);
                            Notification::make()
                                ->title('Supplier Attached')
                                ->success()
                                ->send();
                        }
                    })
                    ->extraModalFooterActions(function (AttachAction $action) {
                        return [
                            CreateAction::make()
                                ->label('Create Supplier')
                                ->icon('heroicon-o-plus')
                                ->modalWidth('4xl')
                                ->schema(self::getCreateAccountMasterTraitFields())
                                ->createAnother(false)
                                ->after(function (RelationManager $livewire, $record) {
                                    // Prevent duplicate attachments
                                    if (!$livewire->ownerRecord->suppliers()->where('account_masters.id', $record->id)->exists()) {
                                        $livewire->ownerRecord->suppliers()->attach($record->id);
                                        Notification::make()
                                            ->title('Supplier Created and Attached')
                                            ->success()
                                            ->send();
                                    }

                                    // Close the modal and reset the form to prevent AttachAction submission
                                    $livewire->dispatch('close-modal');
                                }),
                        ];
                    }),
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
