<?php

namespace App\Filament\Resources\Patches\RelationManagers;

use App\Models\AccountMaster;
use Filament\Actions\Action;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CompaniesRelationManager extends RelationManager
{
    protected static string $relationship = 'companies';

    protected static ?string $title = 'Assigned Customers';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                //
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('account_code')
                    ->label('Customer Code')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('typeMaster.name')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn ($state, $record) => $record->typeMaster?->parent?->name ?? $record->typeMaster?->name ?? 'Customer')
                    ->color('primary'),

                TextInputColumn::make('sequence_no')
                    ->label('Sequence No')
                    ->rules(['nullable', 'integer', 'min:1'])
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false)
                    ->updateStateUsing(function ($record, $state): void {
                        $record->pivot->update([
                            'sequence_no' => $state,
                        ]);
                    }),

                TextColumn::make('distance_km')
                    ->label('Distance (km)')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('addresses.areaTown.area_town')
                    ->label('Primary Area/Town')
                    ->getStateUsing(function ($record): string {
                        $primaryAddress = $record->addresses->firstWhere('is_primary', true);

                        return $primaryAddress?->areaTown?->area_town ?? '-';
                    })
                    ->toggleable(isToggledHiddenByDefault: false),

                TextColumn::make('addresses.areaTown.pin_code')
                    ->label('Pin Code(s)')
                    ->getStateUsing(fn ($record): string => $record->addresses->pluck('areaTown.pin_code')->unique()->implode(', '))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('typeMaster')
                    ->relationship('typeMaster', 'name')
                    ->multiple()
                    ->preload(),
            ])
            ->headerActions([
                Action::make('addAll')
                    ->label('Add All Customers from Territory')
                    ->icon('heroicon-o-plus-circle')
                    ->color('success')
                    ->action(function (): void {
                        $patch = $this->ownerRecord;

                        if (! $patch->territory_id) {
                            Notification::make()->title('No territory selected')->danger()->send();

                            return;
                        }

                        if ($patch->customerPinCodes()->isEmpty()) {
                            Notification::make()->title('No pin codes found')->warning()->send();

                            return;
                        }

                        $companyIds = $patch->eligibleCustomers(AccountMaster::query())->pluck('id');

                        $patch->companies()->syncWithoutDetaching($companyIds);

                        Notification::make()
                            ->title($companyIds->count().' companies added')
                            ->success()
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->modalDescription('This will add all eligible customers from the current territory/pin code without removing existing ones.'),

                AttachAction::make()
                    ->label('Add Customer')
                    ->multiple()
                    ->icon('heroicon-o-plus-circle')
                    ->modalHeading('Attach Customer to Patch')
                    ->modalSubmitActionLabel('Attach')
                    ->recordSelectSearchColumns(['name', 'account_code'])
                    ->recordSelectOptionsQuery(fn (Builder $query): Builder => $this->eligibleCustomersQuery($query))
                    ->recordSelect(fn (Select $select): Select => $select->label('Customer'))
                    ->preloadRecordSelect()
                    ->successNotificationTitle('Company attached successfully'),
            ])
            ->actions([
                DetachAction::make()
                    ->label('Remove')
                    ->icon('heroicon-o-trash')
                    ->action(function ($record, RelationManager $livewire): void {
                        $livewire->ownerRecord->companies()->detach($record->getKey());
                        Notification::make()
                            ->title('Company removed from patch')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                DetachBulkAction::make()
                    ->label('Remove Selected')
                    ->action(function ($records, RelationManager $livewire): void {
                        $livewire->ownerRecord->companies()->detach($records->pluck('id')->all());
                        Notification::make()
                            ->title('Selected companies removed')
                            ->success()
                            ->send();
                    }),
            ]);
    }

    protected function eligibleCustomersQuery(Builder $query): Builder
    {
        return $this->ownerRecord?->eligibleCustomers($query) ?? $query->whereRaw('1 = 0');
    }
}
