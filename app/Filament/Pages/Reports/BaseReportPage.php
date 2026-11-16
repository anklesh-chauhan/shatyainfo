<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Clusters\Reports\ReportsCluster;
use App\Filament\Pages\Reports\Concerns\AuthorizesReportAccess;
use App\Jobs\RunReportExport;
use App\Models\Territory;
use App\Models\User;
use Filament\Actions\ExportAction;
use Filament\Actions\Exports\Exporter;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables\Columns\Column;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\BaseFilter;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;

abstract class BaseReportPage extends Page implements HasTable
{
    use AuthorizesReportAccess;
    use InteractsWithTable;

    protected static bool $isDiscovered = false;

    protected static ?string $cluster = ReportsCluster::class;

    protected string $view = 'filament.pages.reports.table-report';

    /**
     * @var class-string<Exporter>|null
     */
    protected static ?string $exporter = null;

    abstract protected function visibilityPermissionKey(): ?string;

    abstract protected function getReportQuery(): Builder;

    /**
     * @return array<Column>
     */
    abstract protected function getReportColumns(): array;

    protected function dateColumn(): ?string
    {
        return 'created_at';
    }

    protected function userColumn(): ?string
    {
        return 'user_id';
    }

    protected function territoryColumn(): ?string
    {
        return 'territory_id';
    }

    /**
     * @return array<BaseFilter>
     */
    protected function extraReportFilters(): array
    {
        return [];
    }

    protected function constrainVisibility(Builder $query): Builder
    {
        $key = $this->visibilityPermissionKey();

        if ($key === null) {
            return $query;
        }

        return $query->applyVisibility($key);
    }

    protected function defaultSortColumn(): ?string
    {
        return $this->dateColumn();
    }

    protected function defaultSortDirection(): string
    {
        return 'desc';
    }

    protected function applyPeriodFilter(Builder $query, array $data): Builder
    {
        $column = $this->dateColumn();

        if ($column === null) {
            return $query;
        }

        return $query
            ->when(
                $data['from'] ?? null,
                fn (Builder $periodQuery, mixed $date): Builder => $periodQuery->whereDate($column, '>=', $date)
            )
            ->when(
                $data['until'] ?? null,
                fn (Builder $periodQuery, mixed $date): Builder => $periodQuery->whereDate($column, '<=', $date)
            );
    }

    protected function applyUserFilter(Builder $query, mixed $userId): Builder
    {
        $column = $this->userColumn();

        if ($column === null || blank($userId)) {
            return $query;
        }

        return $query->where($column, $userId);
    }

    protected function applyTerritoryFilter(Builder $query, mixed $territoryId): Builder
    {
        $column = $this->territoryColumn();

        if ($column === null || blank($territoryId)) {
            return $query;
        }

        return $query->where($column, $territoryId);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->constrainVisibility($this->getReportQuery()))
            ->columns($this->getReportColumns())
            ->filters([
                ...$this->getPeriodFilter(),
                ...$this->getUserFilter(),
                ...$this->getTerritoryFilter(),
                ...$this->extraReportFilters(),
            ])
            ->headerActions($this->getReportHeaderActions())
            ->defaultSort($this->defaultSortColumn() ?? 'id', $this->defaultSortDirection())
            ->emptyStateHeading('No records for this period')
            ->emptyStateDescription('Try a different date range, employee, or territory.')
            ->striped()
            ->paginated([25, 50, 100]);
    }

    /**
     * @return array<ExportAction>
     */
    protected function getReportHeaderActions(): array
    {
        if (static::$exporter === null) {
            return [];
        }

        return [
            ExportAction::make()
                ->exporter(static::$exporter)
                ->fileDisk('local')
                ->job(RunReportExport::class)
                ->authorize(fn (): bool => static::canAccess()
                    && (auth()->user()?->canAccessBusinessPage('Export:Reports') ?? false)),
        ];
    }

    /**
     * @return array<BaseFilter>
     */
    protected function getPeriodFilter(): array
    {
        $column = $this->dateColumn();

        if ($column === null) {
            return [];
        }

        return [
            Filter::make('period')
                ->schema([
                    DatePicker::make('from')
                        ->label('From')
                        ->default(now()->startOfMonth()),
                    DatePicker::make('until')
                        ->label('Until')
                        ->default(now()),
                ])
                ->query(fn (Builder $query, array $data): Builder => $this->applyPeriodFilter($query, $data)),
        ];
    }

    /**
     * @return array<BaseFilter>
     */
    protected function getUserFilter(): array
    {
        $column = $this->userColumn();

        if ($column === null) {
            return [];
        }

        return [
            SelectFilter::make('employee')
                ->label('Employee')
                ->options(fn (): array => User::query()
                    ->whereExists($this->applyUserFilter(
                        $this->constrainVisibility($this->getReportQuery()),
                        new Expression('users.id'),
                    )->toBase())
                    ->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->query(fn (Builder $query, array $data): Builder => $this->applyUserFilter($query, $data['value'] ?? null)),
        ];
    }

    /**
     * @return array<BaseFilter>
     */
    protected function getTerritoryFilter(): array
    {
        $column = $this->territoryColumn();

        if ($column === null) {
            return [];
        }

        return [
            SelectFilter::make('territory')
                ->label('Territory')
                ->options(fn (): array => Territory::query()
                    ->whereExists($this->applyTerritoryFilter(
                        $this->constrainVisibility($this->getReportQuery()),
                        new Expression('territories.id'),
                    )->toBase())
                    ->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->query(fn (Builder $query, array $data): Builder => $this->applyTerritoryFilter($query, $data['value'] ?? null)),
        ];
    }
}
