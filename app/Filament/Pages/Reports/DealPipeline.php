<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Exports\Reports\DealPipelineExporter;
use App\Filament\Resources\Deals\DealResource;
use App\Models\Deal;
use App\Models\DealStage;
use App\Services\Reports\DealPipelineQuery;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DealPipeline extends BaseReportPage
{
    protected static bool $isDiscovered = true;

    protected static ?string $exporter = DealPipelineExporter::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?string $navigationLabel = 'Deal pipeline';

    protected static ?string $title = 'Deal pipeline';

    protected static ?int $navigationSort = 17;

    protected function visibilityPermissionKey(): ?string
    {
        return 'Deal';
    }

    protected function dateColumn(): ?string
    {
        return 'transaction_date';
    }

    protected function userColumn(): ?string
    {
        return 'owner_id';
    }

    protected function territoryColumn(): ?string
    {
        return null;
    }

    protected function getReportQuery(): Builder
    {
        return app(DealPipelineQuery::class)->builder();
    }

    protected function extraReportFilters(): array
    {
        return [
            SelectFilter::make('status_id')
                ->label('Stage')
                ->options(fn (): array => DealStage::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable(),
        ];
    }

    protected function getReportColumns(): array
    {
        return [
            TextColumn::make('transaction_date')
                ->label('Date')
                ->date()
                ->sortable(),
            TextColumn::make('reference_code')
                ->label('Reference')
                ->searchable(),
            TextColumn::make('deal_name')
                ->label('Deal')
                ->searchable()
                ->sortable(),
            TextColumn::make('owner.name')
                ->label('Owner')
                ->searchable()
                ->sortable(),
            TextColumn::make('accountMaster.name')
                ->label('Account')
                ->placeholder('—')
                ->searchable()
                ->toggleable(),
            TextColumn::make('status.name')
                ->label('Stage')
                ->badge()
                ->sortable(),
            TextColumn::make('amount')
                ->money('INR')
                ->sortable(),
            TextColumn::make('expected_revenue')
                ->label('Expected')
                ->money('INR')
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('expected_close_date')
                ->label('Expected close')
                ->date()
                ->sortable(),
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->recordUrl(fn (Deal $record): string => DealResource::getUrl('edit', ['record' => $record]));
    }
}
