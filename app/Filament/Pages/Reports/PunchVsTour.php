<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Exports\Reports\PunchVsTourExporter;
use App\Filament\Resources\DailyAttendances\DailyAttendanceResource;
use App\Filament\Resources\Visits\VisitResource;
use App\Models\PunchVsTourRow;
use App\Services\Reports\PunchVsTourQuery;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PunchVsTour extends BaseReportPage
{
    protected static bool $isDiscovered = true;

    protected static ?string $exporter = PunchVsTourExporter::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'Punch vs tour';

    protected static ?string $title = 'Punch vs tour';

    protected static ?int $navigationSort = 21;

    protected function visibilityPermissionKey(): ?string
    {
        return null;
    }

    protected function dateColumn(): ?string
    {
        return 'activity_date';
    }

    protected function userColumn(): ?string
    {
        return 'user_id';
    }

    protected function territoryColumn(): ?string
    {
        return 'territory_id';
    }

    protected function getReportQuery(): Builder
    {
        return app(PunchVsTourQuery::class)->builder();
    }

    protected function extraReportFilters(): array
    {
        return [
            SelectFilter::make('mismatch')
                ->label('Exception')
                ->options([
                    'punch_without_visit' => 'Punch without visit',
                    'visit_without_punch' => 'Visit without punch',
                ]),
        ];
    }

    protected function getReportColumns(): array
    {
        return [
            TextColumn::make('activity_date')
                ->label('Date')
                ->date()
                ->sortable(),
            TextColumn::make('mismatch')
                ->label('Exception')
                ->badge()
                ->formatStateUsing(fn (?string $state): string => match ($state) {
                    'punch_without_visit' => 'Punch without visit',
                    'visit_without_punch' => 'Visit without punch',
                    default => (string) $state,
                })
                ->colors([
                    'warning' => 'punch_without_visit',
                    'danger' => 'visit_without_punch',
                ]),
            TextColumn::make('user.name')
                ->label('Employee')
                ->searchable(),
            TextColumn::make('territory.name')
                ->label('Territory')
                ->placeholder('—')
                ->toggleable(),
            TextColumn::make('first_punch_in')
                ->label('First in')
                ->placeholder('—'),
            TextColumn::make('last_punch_out')
                ->label('Last out')
                ->placeholder('—')
                ->toggleable(),
            TextColumn::make('visit_status')
                ->label('Visit status')
                ->badge()
                ->placeholder('—'),
            TextColumn::make('document_number')
                ->label('Visit no.')
                ->placeholder('—')
                ->searchable()
                ->toggleable(),
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->recordUrl(function (PunchVsTourRow $record): ?string {
                if (blank($record->source_id)) {
                    return null;
                }

                return match ($record->mismatch) {
                    'punch_without_visit' => DailyAttendanceResource::getUrl('edit', ['record' => $record->source_id]),
                    'visit_without_punch' => VisitResource::getUrl('edit', ['record' => $record->source_id]),
                    default => null,
                };
            });
    }
}
