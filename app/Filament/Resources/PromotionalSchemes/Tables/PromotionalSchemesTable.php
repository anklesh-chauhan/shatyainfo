<?php

namespace App\Filament\Resources\PromotionalSchemes\Tables;

use App\Services\Marketing\PromotionalSchemeRegister;
use Closure;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Support\Enumerable;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class PromotionalSchemesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('scheme_type')
                    ->badge(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'gray' => 'draft',
                        'success' => 'active',
                        'warning' => 'expired',
                        'danger' => 'cancelled',
                    ]),

                Tables\Columns\TextColumn::make('valid_from')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('valid_to')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('applies_to')
                    ->badge(),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->using(self::changeRecords('delete')),
                    ForceDeleteBulkAction::make()->using(self::changeRecords('force-delete')),
                    RestoreBulkAction::make()->using(self::changeRecords('restore')),
                ]),
            ]);
    }

    private static function changeRecords(string $operation): Closure
    {
        return function (BulkAction $action, Enumerable $records) use ($operation): void {
            $ids = $records->pluck('id')->map(intval(...))->values()->all();
            if ($ids === []) {
                return;
            }

            try {
                $count = app(PromotionalSchemeRegister::class)->changeRecords(auth()->user(), $ids, $operation);
                $action->reportBulkProcessingSuccessfulRecordsCount($count);
            } catch (HttpExceptionInterface $error) {
                Notification::make()->danger()->title($error->getMessage() ?: 'Scheme action unavailable.')->send();
                $action->halt();
            }
        };
    }
}
