<?php

namespace App\Filament\Resources\MarketingCampaigns\Tables;

use App\Services\Marketing\MarketingCampaignRegister;
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

class MarketingCampaignsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('campaign_number')
                    ->label('Number')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('promotionalScheme.name')
                    ->label('Scheme')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->colors([
                        'gray' => 'draft',
                        'success' => 'active',
                        'info' => 'closed',
                        'danger' => 'cancelled',
                    ]),

                Tables\Columns\TextColumn::make('start_date')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('end_date')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('items_count')
                    ->counts('items')
                    ->label('Items'),

                Tables\Columns\TextColumn::make('territory_quotas_count')
                    ->counts('territoryQuotas')
                    ->label('Territory Quotas'),
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
                $count = app(MarketingCampaignRegister::class)->changeRecords(auth()->user(), $ids, $operation);
                $action->reportBulkProcessingSuccessfulRecordsCount($count);
            } catch (HttpExceptionInterface $error) {
                Notification::make()->danger()->title($error->getMessage() ?: 'Campaign action unavailable.')->send();
                $action->halt();
            }
        };
    }
}
