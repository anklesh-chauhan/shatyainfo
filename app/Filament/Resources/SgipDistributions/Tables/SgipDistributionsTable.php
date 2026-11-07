<?php

namespace App\Filament\Resources\SgipDistributions\Tables;

use App\Models\ApprovalSetting;
use App\Models\SgipDistribution;
use App\Services\SGIPComplianceService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Throwable;

class SgipDistributionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('doctor.name')
                    ->label('Doctor')
                    ->searchable(),

                Tables\Columns\TextColumn::make('user')
                    ->label('Sales Employee')
                    ->getStateUsing(fn ($record) => $record->user?->employee?->full_name
                    )
                    ->visible(fn () => ! Auth::user()->hasRole('sales_user')),

                Tables\Columns\TextColumn::make('visit_date')
                    ->date(),

                Tables\Columns\TextColumn::make('total_value')
                    ->money('INR'),

                Tables\Columns\TextColumn::make('approval_status')
                    ->badge()
                    ->colors([
                        'warning' => 'draft',
                        'info' => 'submitted',
                        'success' => 'approved',
                        'danger' => 'rejected',
                    ]),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn ($record) => $record->approval_status === 'draft'),

                Action::make('submit')
                    ->label('Submit')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->visible(fn ($record) => $record->approval_status === 'draft')
                    ->action(function ($record): void {
                        if (! $record->items()->exists()) {
                            Notification::make()
                                ->title('Add at least one item before submitting.')
                                ->danger()
                                ->send();

                            return;
                        }

                        try {
                            SGIPComplianceService::validate($record, true);
                            $record->update(['approval_status' => 'submitted']);

                            Notification::make()
                                ->title('SGIP distribution submitted')
                                ->success()
                                ->send();
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title('Unable to submit SGIP distribution')
                                ->body(collect($exception->errors())->flatten()->implode(' '))
                                ->danger()
                                ->send();
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->title('Unable to submit SGIP distribution')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('approve')
                    ->label(fn (): string => ApprovalSetting::moduleRequiresApproval(SgipDistribution::class) ? 'Approve & Deduct Stock' : 'Post & Deduct Stock')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn ($record): bool => $record->approval_status === 'submitted' && ! $record->isInventoryPosted())
                    ->action(function ($record): void {
                        try {
                            SGIPComplianceService::validate($record, true);
                            $record->approve();

                            Notification::make()
                                ->title('SGIP posted and stock deducted')
                                ->success()
                                ->send();
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title('Unable to post SGIP distribution')
                                ->body(collect($exception->errors())->flatten()->implode(' '))
                                ->danger()
                                ->send();
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->title('Unable to post SGIP distribution')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
