<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Exports\Reports\LeadPipelineExporter;
use App\Filament\Resources\Leads\LeadResource;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Services\Reports\LeadPipelineQuery;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LeadPipeline extends BaseReportPage
{
    protected static bool $isDiscovered = true;

    protected static ?string $exporter = LeadPipelineExporter::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedFunnel;

    protected static ?string $navigationLabel = 'Lead pipeline';

    protected static ?string $title = 'Lead pipeline';

    protected static ?int $navigationSort = 16;

    protected function visibilityPermissionKey(): ?string
    {
        return 'Lead';
    }

    protected function dateColumn(): ?string
    {
        return 'transaction_date';
    }

    protected function userColumn(): ?string
    {
        return 'owner_id';
    }

    protected function getReportQuery(): Builder
    {
        return app(LeadPipelineQuery::class)->builder();
    }

    protected function extraReportFilters(): array
    {
        return [
            SelectFilter::make('status_id')
                ->label('Status')
                ->options(fn (): array => LeadStatus::query()->orderBy('name')->pluck('name', 'id')->all())
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
            TextColumn::make('owner.name')
                ->label('Owner')
                ->searchable()
                ->sortable(),
            TextColumn::make('territory.name')
                ->label('Territory')
                ->placeholder('—')
                ->toggleable(),
            TextColumn::make('accountMaster.name')
                ->label('Account')
                ->placeholder('—')
                ->searchable(),
            TextColumn::make('status.name')
                ->label('Status')
                ->badge()
                ->sortable(),
            TextColumn::make('annual_revenue')
                ->label('Value')
                ->money('INR')
                ->sortable(),
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->recordUrl(fn (Lead $record): string => LeadResource::getUrl('edit', ['record' => $record]));
    }
}
