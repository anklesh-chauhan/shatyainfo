<?php

namespace App\Filament\Resources\LeaveApplications\Tables;

use App\Orchestrators\LeaveApplicationOrchestrator;
use App\Services\Field\FieldLeaveService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class LeaveApplicationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('latestCancellation'))
            ->columns([
                TextColumn::make('employee.full_name')
                    ->label('Employee'),

                TextColumn::make('leaveType.name')->label('Leave'),

                TextColumn::make('from_date')->date(),
                TextColumn::make('to_date')->date(),

                TextColumn::make('approval_status')
                    ->badge()
                    ->colors([
                        'primary' => 'applied',
                        'warning' => 'draft',
                        'success' => 'approved',
                        'danger' => 'rejected',
                    ]),

                TextColumn::make('latestCancellation.approval_status')->label('Cancellation')->badge(),
                TextColumn::make('applied_at')->dateTime(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                Action::make('cancelLeave')->label('Cancel / Request Cancellation')->requiresConfirmation()
                    ->visible(fn ($record): bool => FieldLeaveService::canCancel($record))
                    ->authorize(fn ($record): bool => auth()->user()->can('update', $record) || (int) $record->employee_id === (int) auth()->user()->employee?->id)
                    ->action(function ($record): void {
                        try {
                            app(LeaveApplicationOrchestrator::class)->cancel($record->id, auth()->id());
                            Notification::make()->title('Cancellation processed or submitted for approval')->success()->send();
                        } catch (\LogicException|ValidationException $exception) {
                            Notification::make()->title('Unable to cancel leave')->body($exception->getMessage())->danger()->send();
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
