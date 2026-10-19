<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Clusters\Reports\ReportsCluster;
use App\Models\AccountMaster;
use App\Models\OrganizationalUnit;
use App\Services\AccountReportingService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;

class AccountReachFrequency extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $cluster = ReportsCluster::class;

    protected static ?string $title = 'Account Reach & Frequency';

    protected string $view = 'filament.pages.reports.table-report';

    public string $month = '';

    public ?int $divisionId = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewAny', AccountMaster::class) ?? false;
    }

    public function mount(): void
    {
        $this->month = now()->format('Y-m');
    }

    private function reportQuery(): Builder
    {
        Validator::make(['month' => $this->month], ['month' => ['required', 'date_format:Y-m']])->validate();
        $end = CarbonImmutable::createFromFormat('!Y-m', $this->month)->endOfMonth()->toDateString();

        return app(AccountReportingService::class)->monthlyQuery($this->month)
            ->when($this->divisionId, fn ($q) => $q->whereHas('divisionProfiles', fn ($q) => $q->where('division_ou_id', $this->divisionId)->where('effective_from', '<=', $end)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $end))))
            ->with(['divisionProfiles' => fn ($q) => $q
                ->where('division_ou_id', $this->divisionId)->where('effective_from', '<=', $end)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $end))->with(['ratingType', 'potentialUnit'])]);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('period')->label('Reporting Month / Division')->schema([
            TextInput::make('month')->required()->rules(['date_format:Y-m'])->default(fn () => $this->month)->helperText('YYYY-MM. Targets and actual calls are account-wide. Segmentation is shown at month end.'),
            Select::make('division_id')->options(fn () => OrganizationalUnit::whereHas('typeMaster', fn ($q) => $q->where('name', 'Division')->where('typeable_type', OrganizationalUnit::class))->pluck('name', 'id'))->default(fn () => $this->divisionId)->placeholder('Account-wide segmentation'),
        ])->action(function (array $data): void {
            $this->month = $data['month'];
            $this->divisionId = filled($data['division_id'] ?? null) ? (int) $data['division_id'] : null;
            $this->resetTable();
        }),
            Action::make('export')->label('Export CSV')->action(function () {
                abort_unless(static::canAccess(), 403);

                return response()->streamDownload(function (): void {
                    $stream = fopen('php://output', 'w');
                    fputcsv($stream, ['Month', 'Account Code', 'Account', 'Owner at Month End', 'Territory at Month End', 'Specialty (Current)', 'Therapy Area (Current)', 'Segment', 'Potential', 'Unit', 'Currency', 'Potential Period', 'Assessed On', 'Target Calls', 'Completed Calls', 'Gap', 'Achievement %', 'Reached'], ',', '"', '');
                    foreach ($this->getFilteredTableQuery()->orderBy('id')->lazy(200) as $account) {
                        $metrics = app(AccountReportingService::class)->metrics($account);
                        $history = $account->assignmentHistory->first();
                        $profile = $account->divisionProfiles->first();
                        $row = [$this->month, $account->account_code, $account->name, $history?->owner?->name, $history?->territory?->name, $account->specialty?->name, $account->therapyArea?->name, $profile?->ratingType?->name, $profile?->potential_value, $profile?->potentialUnit?->name, $profile?->currency, $profile?->potential_period, $profile?->assessed_on?->toDateString(), $metrics['target'], $metrics['completed'], $metrics['gap'], $metrics['achievement'], $metrics['reached'] ? 'Yes' : 'No'];
                        fputcsv($stream, array_map(fn ($value) => is_string($value) && preg_match('/^[=+@\-\t\r\n]/', $value) ? "'".$value : $value, $row), ',', '"', '');
                    }
                    fclose($stream);
                }, 'account-reach-frequency-'.$this->month.'.csv');
            })];
    }

    public function table(Table $table): Table
    {
        $metrics = fn ($record) => app(AccountReportingService::class)->metrics($record);

        return $table->query(fn () => $this->reportQuery())->heading(fn () => 'Account-wide calls — '.$this->month)->columns([
            TextColumn::make('account_code')->searchable(), TextColumn::make('name')->searchable(),
            TextColumn::make('owner_at_month_end')->state(fn ($record) => $record->assignmentHistory->first()?->owner?->name)->placeholder('No recorded history'),
            TextColumn::make('territory_at_month_end')->state(fn ($record) => $record->assignmentHistory->first()?->territory?->name)->placeholder('No recorded history'),
            TextColumn::make('specialty.name')->label('Specialty (current)'), TextColumn::make('therapyArea.name')->label('Therapy area (current)'),
            TextColumn::make('segment')->state(fn ($record) => $record->divisionProfiles->first()?->ratingType?->name)->placeholder('Not assessed'),
            TextColumn::make('potential')->state(fn ($record) => $record->divisionProfiles->first()?->potential_value),
            TextColumn::make('unit')->state(fn ($record) => $record->divisionProfiles->first()?->potentialUnit?->name),
            TextColumn::make('currency')->state(fn ($record) => $record->divisionProfiles->first()?->currency),
            TextColumn::make('potential_period')->state(fn ($record) => $record->divisionProfiles->first()?->potential_period),
            TextColumn::make('target')->state(fn ($record) => $metrics($record)['target'])->placeholder('Not configured'),
            TextColumn::make('completed_calls')->label('Completed Calls'),
            TextColumn::make('gap')->state(fn ($record) => $metrics($record)['gap'])->placeholder('—'),
            TextColumn::make('achievement')->state(fn ($record) => $metrics($record)['achievement'])->suffix('%')->placeholder('—'),
            TextColumn::make('reached')->state(fn ($record) => $metrics($record)['reached'] ? 'Yes' : 'No'),
        ])->defaultSort('name')->paginated([25, 50, 100]);
    }
}
