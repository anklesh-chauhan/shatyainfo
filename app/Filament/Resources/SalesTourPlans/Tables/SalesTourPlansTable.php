<?php

namespace App\Filament\Resources\SalesTourPlans\Tables;

use App\Models\SalesTourPlan;
use App\Services\Field\FieldMtpService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class SalesTourPlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('approval'))
            ->columns([
                Tables\Columns\TextColumn::make('sales_employee')
                    ->label('Sales Employee')
                    ->getStateUsing(fn ($record) => $record->user?->employee?->full_name
                            ?? $record->user?->email
                    )
                    ->sortable(
                        query: function (Builder $query, string $direction): Builder {
                            return $query
                                ->leftJoin('users', 'users.id', '=', 'sales_tour_plans.user_id')
                                ->leftJoin('employees', 'employees.login_id', '=', 'users.id')
                                ->orderBy('employees.first_name', $direction)
                                ->select('sales_tour_plans.*');
                        }
                    )
                    ->searchable(
                        query: function (Builder $query, string $search): Builder {
                            return $query->whereHas('user.employee', function ($q) use ($search) {
                                $q->whereLike('first_name', "%{$search}%")
                                    ->orWhereLike('last_name', "%{$search}%")
                                    ->orWhereLike('email', "%{$search}%");
                            });
                        }
                    ),

                Tables\Columns\TextColumn::make('month')->label('Month')->sortable()->date('F Y'),
                Tables\Columns\TextColumn::make('approval_status')
                    ->colors([
                        'secondary' => 'draft',
                        'warning' => 'submitted',
                        'success' => 'approved',
                        'danger' => 'rejected',
                    ])->sortable()->searchable()->label('Status')->badge(),
                Tables\Columns\TextColumn::make('approvedBy.name')->label('Approved By'),
                Tables\Columns\TextColumn::make('approved_at')->dateTime()->label('Approved At'),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('submit_tour_plan')
                    ->label('Submit Tour Plan')
                    ->visible(fn (SalesTourPlan $record): bool => app(FieldMtpService::class)->canSubmitFromAdmin(auth()->user(), $record))
                    ->requiresConfirmation()
                    ->action(function (SalesTourPlan $record, FieldMtpService $plans): void {
                        try {
                            $plan = $plans->submitFromAdmin(auth()->user(), $record->id);
                        } catch (ValidationException|HttpExceptionInterface $exception) {
                            Notification::make()->danger()->title('Tour plan could not be submitted')
                                ->body($exception->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()
                            ->title($plan->isApproved() ? 'Tour plan approved' : 'Tour plan submitted for approval')->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('submit_tour_plans')
                        ->label('Submit Tour Plans')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records, FieldMtpService $plans): void {
                            $submitted = 0;
                            $firstError = null;

                            foreach ($records as $record) {
                                try {
                                    $plans->submitFromAdmin(auth()->user(), $record->id);
                                    $submitted++;
                                } catch (ValidationException|HttpExceptionInterface $exception) {
                                    $firstError ??= $exception->getMessage() ?: 'This tour plan cannot be submitted.';
                                }
                            }

                            $failed = $records->count() - $submitted;
                            Notification::make()->status($failed > 0 ? 'warning' : 'success')
                                ->title("Tour plans submitted: {$submitted}. Skipped: {$failed}.")
                                ->body($firstError)->send();
                        }),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
