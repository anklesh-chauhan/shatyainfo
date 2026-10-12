<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Exports\Reports\DcrRegisterExporter;
use App\Filament\Resources\SalesDcrs\SalesDcrResource;
use App\Models\SalesDcr;
use App\Services\Reports\DcrRegisterQuery;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DcrRegister extends BaseReportPage
{
    protected static bool $isDiscovered = true;

    protected static ?string $exporter = DcrRegisterExporter::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'DCR register';

    protected static ?string $title = 'DCR register';

    protected static ?int $navigationSort = 10;

    protected function visibilityPermissionKey(): ?string
    {
        return 'SalesDcr';
    }

    protected function dateColumn(): ?string
    {
        return 'dcr_date';
    }

    protected function getReportQuery(): Builder
    {
        return app(DcrRegisterQuery::class)->builder();
    }

    protected function extraReportFilters(): array
    {
        return [
            SelectFilter::make('approval_status')
                ->label('Status')
                ->options([
                    'draft' => 'Draft',
                    'pending' => 'Pending',
                    'approved' => 'Approved',
                    'rejected' => 'Rejected',
                ]),
        ];
    }

    protected function getReportColumns(): array
    {
        return [
            TextColumn::make('dcr_date')
                ->label('DCR date')
                ->date()
                ->sortable(),
            TextColumn::make('user.name')
                ->label('Employee')
                ->searchable()
                ->sortable(),
            TextColumn::make('territory.name')
                ->label('Territory')
                ->placeholder('—')
                ->toggleable(),
            TextColumn::make('visits_count')
                ->label('Visits')
                ->numeric()
                ->sortable(),
            TextColumn::make('distance_covered')
                ->label('Distance (km)')
                ->numeric(decimalPlaces: 2)
                ->sortable()
                ->toggleable(),
            TextColumn::make('total_expense')
                ->label('Expense')
                ->money('INR')
                ->sortable(),
            TextColumn::make('total_expense_approved')
                ->label('Approved')
                ->money('INR')
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('total_expense_rejected')
                ->label('Rejected')
                ->money('INR')
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('approval_status')
                ->label('Status')
                ->badge()
                ->colors([
                    'gray' => 'draft',
                    'info' => 'pending',
                    'success' => 'approved',
                    'danger' => 'rejected',
                ]),
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->recordUrl(fn (SalesDcr $record): string => SalesDcrResource::getUrl('edit', ['record' => $record]));
    }
}
