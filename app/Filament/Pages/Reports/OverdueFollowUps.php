<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Exports\Reports\OverdueFollowUpExporter;
use App\Filament\Resources\FollowUps\FollowUpResource;
use App\Models\FollowUp;
use App\Services\Reports\OverdueFollowUpQuery;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OverdueFollowUps extends BaseReportPage
{
    protected static bool $isDiscovered = true;

    protected static ?string $exporter = OverdueFollowUpExporter::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Overdue follow-ups';

    protected static ?string $title = 'Overdue follow-ups';

    protected static ?int $navigationSort = 19;

    protected function visibilityPermissionKey(): ?string
    {
        return 'FollowUp';
    }

    protected function dateColumn(): ?string
    {
        return 'follow_up_date';
    }

    protected function territoryColumn(): ?string
    {
        return null;
    }

    protected function applyPeriodFilter(Builder $query, array $data): Builder
    {
        return $query
            ->when(
                $data['from'] ?? null,
                function (Builder $periodQuery, mixed $date): Builder {
                    return $periodQuery->where(function (Builder $dueQuery) use ($date): void {
                        $dueQuery
                            ->whereDate('next_follow_up_date', '>=', $date)
                            ->orWhereDate('follow_up_date', '>=', $date);
                    });
                }
            )
            ->when(
                $data['until'] ?? null,
                function (Builder $periodQuery, mixed $date): Builder {
                    return $periodQuery->where(function (Builder $dueQuery) use ($date): void {
                        $dueQuery
                            ->whereDate('next_follow_up_date', '<=', $date)
                            ->orWhereDate('follow_up_date', '<=', $date);
                    });
                }
            );
    }

    protected function getReportQuery(): Builder
    {
        return app(OverdueFollowUpQuery::class)->builder();
    }

    protected function getReportColumns(): array
    {
        return [
            TextColumn::make('follow_up_date')
                ->label('Follow-up')
                ->dateTime('d M Y, h:i A')
                ->sortable(),
            TextColumn::make('next_follow_up_date')
                ->label('Next due')
                ->dateTime('d M Y, h:i A')
                ->placeholder('—')
                ->sortable(),
            TextColumn::make('user.name')
                ->label('Assigned to')
                ->searchable(),
            TextColumn::make('followupable_type')
                ->label('Related')
                ->formatStateUsing(fn (?string $state): string => class_basename((string) $state))
                ->toggleable(),
            TextColumn::make('contactDetail.full_name')
                ->label('To whom')
                ->placeholder('—')
                ->toggleable(),
            TextColumn::make('status.name')
                ->label('Status')
                ->badge()
                ->placeholder('Open'),
            TextColumn::make('priority.name')
                ->label('Priority')
                ->placeholder('—')
                ->toggleable(),
            TextColumn::make('interaction')
                ->limit(40)
                ->placeholder('—'),
        ];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->recordUrl(fn (FollowUp $record): string => FollowUpResource::getUrl('edit', ['record' => $record]));
    }
}
