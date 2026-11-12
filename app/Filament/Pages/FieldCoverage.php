<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\FieldCoverageStatsWidget;
use App\Models\FieldUserHq;
use App\Models\LocationMaster;
use App\Models\User;
use App\Services\Field\FieldCoverageService;
use App\Services\Field\FieldTeamOnboardingService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class FieldCoverage extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 70;

    protected static string|\UnitEnum|null $navigationGroup = 'Field Operations';

    protected static ?string $navigationLabel = 'Customer Coverage';

    protected static ?string $title = 'Customer coverage and frequency';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            'achieved' => 'Achieved',
            'on_track' => 'On track',
            'due' => 'Due',
            'missed' => 'Missed',
            'not_configured' => 'Not configured',
            'not_required' => 'Not required',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusColors(): array
    {
        return [
            'achieved' => 'success',
            'on_track' => 'info',
            'due' => 'warning',
            'missed' => 'danger',
            'not_configured' => 'gray',
            'not_required' => 'gray',
        ];
    }

    public static function canAccess(): bool
    {
        return FieldTeamOnboardingService::canManage(auth()->user()) || (bool) auth()->user()?->can('ViewAny:AccountMaster');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->fill([
            'month' => today()->format('Y-m'),
            'user_id' => auth()->id(),
            'hq_filter' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $canManage = FieldTeamOnboardingService::canManage(auth()->user());

        return $schema
            ->components([
                Section::make()
                    ->description('Coverage is calculated from completed visits on approved DCRs. Targets are prorated by assigned calendar days; joint participation does not add owner coverage.')
                    // ->icon(Heroicon::OutlinedFunnel)
                    ->schema([
                        TextInput::make('month')
                            ->label('Month')
                            ->type('month')
                            ->required()
                            ->extraInputAttributes(['max' => today()->format('Y-m')])
                            ->live()
                            ->afterStateUpdated(fn () => $this->resetTable()),
                        Select::make('hq_filter')
                            ->label('HQ')
                            ->placeholder('All HQs')
                            ->searchable()
                            ->options(fn (): array => LocationMaster::query()
                                ->whereIn(
                                    'id',
                                    FieldUserHq::query()
                                        ->when(! $canManage, fn ($query) => $query->where('user_id', auth()->id()))
                                        ->select('location_master_id')
                                )
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->live()
                            ->afterStateUpdated(function (?int $state, Set $set): void {
                                if ($canManage && $state) {
                                    $set('user_id', (int) (FieldUserHq::query()
                                        ->where('location_master_id', $state)
                                        ->value('user_id') ?? auth()->id()));
                                }

                                $this->resetTable();
                            }),
                        Select::make('user_id')
                            ->label('Representative')
                            ->required()
                            ->searchable()
                            ->disabled(fn (): bool => ! $canManage)
                            ->dehydrated()
                            ->options(fn (Get $get): array => User::query()
                                ->when(
                                    $get('hq_filter'),
                                    fn ($query, $hqId) => $query->whereIn(
                                        'id',
                                        FieldUserHq::query()->where('location_master_id', $hqId)->select('user_id')
                                    )
                                )
                                ->when(! $canManage, fn ($query) => $query->whereKey(auth()->id()))
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->live()
                            ->afterStateUpdated(fn () => $this->resetTable()),
                    ])
                    ->columns(3),
            ])
            ->statePath('data');
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (
                ?string $search,
                ?array $filters,
                ?string $sortColumn,
                ?string $sortDirection,
                int|string $page,
                int|string $recordsPerPage,
            ): LengthAwarePaginator {
                abort_unless(static::canAccess(), 403);

                $report = $this->coverageReport();
                $filters ??= [];
                $page = max(1, (int) $page);
                $recordsPerPage = max(1, (int) $recordsPerPage);

                $records = collect($report['customers'])
                    ->mapWithKeys(fn (array $customer): array => [
                        (string) $customer['account_id'] => $customer,
                    ])
                    ->when(
                        filled($search),
                        fn (Collection $rows): Collection => $rows->filter(
                            fn (array $customer): bool => str_contains(
                                Str::lower((string) $customer['name']),
                                Str::lower($search),
                            )
                        )
                    )
                    ->when(
                        filled($status = $filters['status']['value'] ?? null),
                        fn (Collection $rows): Collection => $rows->where('status', $status)
                    )
                    ->when(
                        filled($sortColumn),
                        fn (Collection $rows): Collection => $rows->sortBy(
                            $sortColumn,
                            SORT_REGULAR,
                            $sortDirection === 'desc',
                        )
                    );

                return new LengthAwarePaginator(
                    $records->forPage($page, $recordsPerPage),
                    total: $records->count(),
                    perPage: $recordsPerPage,
                    currentPage: $page,
                );
            })
            ->columns([
                TextColumn::make('name')
                    ->label('Customer')
                    ->sortable(),
                TextColumn::make('assigned_days')
                    ->label('Assigned days')
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('target_calls')
                    ->label('Target')
                    ->alignEnd()
                    ->placeholder('Not configured'),
                TextColumn::make('completed_calls')
                    ->label('Completed')
                    ->alignEnd(),
                TextColumn::make('pending_calls')
                    ->label('Unapproved')
                    ->alignEnd(),
                TextColumn::make('remaining_calls')
                    ->label('Remaining')
                    ->alignEnd()
                    ->placeholder('N/A'),
                TextColumn::make('last_visit_date')
                    ->label('Last visit')
                    ->placeholder('—')
                    ->date(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => static::statusLabels()[$state] ?? str_replace('_', ' ', (string) $state))
                    ->color(fn (?string $state): string => static::statusColors()[$state] ?? 'gray'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(static::statusLabels()),
            ])
            ->heading(fn (): string => 'Assigned customers')
            ->description(function (): string {
                $report = $this->coverageReport();

                return 'Current HQ: '.($report['hq']['name'] ?? 'Not assigned')
                    .' · As of '.$report['as_of']
                    .' · '.$report['month'];
            })
            ->searchable()
            ->striped()
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('No customers in this view')
            ->emptyStateDescription('Try a different month, representative, search, or status filter.')
            ->emptyStateIcon(Heroicon::OutlinedMagnifyingGlass);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('form'),
            EmbeddedTable::make(),
        ]);
    }

    /**
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            FieldCoverageStatsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function getWidgetData(): array
    {
        return [
            'report' => $this->coverageReport(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function coverageReport(): array
    {
        abort_unless(static::canAccess(), 403);

        $canManage = FieldTeamOnboardingService::canManage(auth()->user());
        $month = (string) ($this->data['month'] ?? today()->format('Y-m'));
        $userId = (int) ($this->data['user_id'] ?? auth()->id());

        if (! $canManage) {
            $userId = (int) auth()->id();
        }

        return app(FieldCoverageService::class)->forMonth(auth()->user(), $userId, $month);
    }
}
