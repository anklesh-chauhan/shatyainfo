<?php

namespace App\Filament\Resources\SgipDistributions\Tables;

use App\Models\SgipDistribution;
use App\Services\Marketing\SgipRegister;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SgipDistributionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('Distribution')->sortable(),
                TextColumn::make('doctor.name')->label('Doctor')->searchable(),
                TextColumn::make('user.name')->label('Sales Employee')->searchable(),
                TextColumn::make('visit_date')->date()->sortable(),
                TextColumn::make('total_value')->money('INR'),
                TextColumn::make('approval_status')->badge(),
                TextColumn::make('inventory_posted_at')->dateTime(),
            ])
            ->filters([
                SelectFilter::make('approval_status')->options(['draft' => 'Draft', 'submitted' => 'Submitted', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled']),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::bulk('delete', 'Delete', 'deleteAny'),
                    self::bulk('force-delete', 'Permanently Delete', 'forceDeleteAny'),
                    self::bulk('restore', 'Restore', 'restoreAny'),
                ]),
            ]);
    }

    private static function bulk(string $action, string $label, string $ability): BulkAction
    {
        return BulkAction::make($action)->label($label)
            ->authorize(fn (): bool => auth()->user()->can($ability, SgipDistribution::class))
            ->requiresConfirmation()->deselectRecordsAfterCompletion()
            ->action(function (Collection $records) use ($action): void {
                try {
                    app(SgipRegister::class)->act(auth()->user(), $records->modelKeys(), $action, bulk: true);
                } catch (HttpException $exception) {
                    if ($exception->getStatusCode() !== 409) {
                        throw $exception;
                    }
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    return;
                }
                Notification::make()->title('SGIP distributions updated')->success()->send();
            });
    }
}
