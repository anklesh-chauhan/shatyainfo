<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\CompanySetupReminder;
use App\Filament\Widgets\FieldActivityStatsWidget;
use App\Jobs\ProcessDailyAttendanceJob;
use App\Models\AttendancePunch;
use App\Models\DailyAttendance;
use App\Models\EmployeeAttendanceStatus;
use App\Models\User;
use App\Services\Reports\FieldVisualReport;
use App\Support\DashboardLayout;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static string|\UnitEnum|null $navigationGroup = 'My Workspace';

    protected static ?int $navigationSort = 10;

    public function booted(): void
    {
        $period = is_array($this->filters) ? ($this->filters['period'] ?? null) : null;

        if (filled($period)) {
            return;
        }

        $this->filters = [
            ...(is_array($this->filters) ? $this->filters : []),
            'period' => 'last_7',
        ];

        $this->getFiltersForm()->fill($this->filters);

        if ($this->persistsFiltersInSession()) {
            session()->put($this->getFiltersSessionKey(), $this->filters);
        }
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getWidgetsContentComponent(),
        ]);
    }

    /**
     * @return int | array<string, ?int>
     */
    public function getColumns(): int|array
    {
        return [
            'default' => 1,
            'lg' => 2,
        ];
    }

    /**
     * @return array<class-string<Widget>|WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        return [
            CompanySetupReminder::class,
            FieldActivityStatsWidget::class,
            ...app(DashboardLayout::class)->chartWidgets(Auth::user() instanceof User ? Auth::user() : null),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            ...$this->attendanceActions(),
            $this->periodAction(),
            $this->customizeReportAction(),
            $this->restoreReportAction(),
        ];
    }

    protected function periodAction(): ActionGroup
    {
        return ActionGroup::make(
            collect(FieldVisualReport::PERIODS)
                ->map(fn (string $label, string $key): Action => Action::make('period'.$key)
                    ->label($label)
                    ->icon(Heroicon::OutlinedCheck)
                    ->color(fn (): string => $this->currentPeriodKey() === $key ? 'primary' : 'gray')
                    ->alpineClickHandler(fn (): string => "close(); \$wire.mountAction('period{$key}')")
                    ->extraAttributes(fn (): array => [
                        'class' => $this->currentPeriodKey() === $key ? 'period-option fi-selected' : 'period-option',
                    ])
                    ->action(function () use ($key): void {
                        $this->selectPeriod($key);
                    }))
                ->values()
                ->all()
        )
            ->label(fn (): string => FieldVisualReport::PERIODS[$this->currentPeriodKey()])
            ->icon(Heroicon::OutlinedCalendarDays)
            ->button()
            ->outlined()
            ->color('gray')
            ->dropdownPlacement('bottom-end');
    }

    protected function currentPeriodKey(): string
    {
        $period = is_array($this->filters) ? ($this->filters['period'] ?? null) : null;

        return array_key_exists((string) $period, FieldVisualReport::PERIODS)
            ? (string) $period
            : 'last_7';
    }

    protected function selectPeriod(string $key): void
    {
        if (! array_key_exists($key, FieldVisualReport::PERIODS)) {
            return;
        }

        $this->filters = [
            ...(is_array($this->filters) ? $this->filters : []),
            'period' => $key,
        ];

        if ($this->persistsFiltersInSession()) {
            session()->put($this->getFiltersSessionKey(), $this->filters);
        }
    }

    /**
     * @return array<Action>
     */
    protected function attendanceActions(): array
    {
        $employee = Auth::user()?->employee;

        if (! $employee) {
            return [];
        }

        $todayAttendance = DailyAttendance::where('employee_id', $employee->id)
            ->whereDate('attendance_date', today())
            ->first();

        // ────────────────────────────── CHECK IN ──────────────────────────────
        if (! $todayAttendance) {
            return [
                Action::make('punchIn')
                    ->label('👋 Punch In')
                    ->button()
                    ->color('success')
                    ->icon('heroicon-o-clock')
                    ->modal(false)
                    ->requiresConfirmation(false)
                    ->extraAttributes([
                        'x-on:click.prevent' => 'startPunchIn($wire)',
                    ]),
            ];
        }

        // ────────────────────────────── CHECK OUT ──────────────────────────────
        if ($todayAttendance && $todayAttendance->punches()->where('punch_type', 'out')->doesntExist()) {
            return [
                Action::make('punchOut')
                    ->label('🚪 Punch Out')
                    ->color('danger')
                    ->icon('heroicon-o-arrow-right-on-rectangle')
                    ->modal(false)
                    ->button()
                    ->requiresConfirmation(false)
                    ->extraAttributes([
                        'x-on:click.prevent' => 'startPunchOut($wire)',
                    ]),
            ];
        }

        // ────────────────────────────── COMPLETED ──────────────────────────────
        return [
            Action::make('completed')
                ->label('✔ Attendance Completed')
                ->color('gray')
                ->disabled()
                ->icon('heroicon-o-check-circle'),
        ];
    }

    protected function customizeReportAction(): Action
    {
        return Action::make('customizeReport')
            ->label('Customize')
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->color('gray')
            ->modalHeading('Customize visual report')
            ->modalDescription('Choose the charts you use every day. This is saved on your account. Numbers still follow what you are allowed to see.')
            ->modalSubmitActionLabel('Save charts')
            ->fillForm(fn (): array => [
                'widgets' => app(DashboardLayout::class)->selected(Auth::user() instanceof User ? Auth::user() : null),
            ])
            ->schema([
                CheckboxList::make('widgets')
                    ->label('Charts')
                    ->options(fn (): array => app(DashboardLayout::class)->options())
                    ->descriptions(fn (): array => app(DashboardLayout::class)->descriptions())
                    ->columns(1)
                    ->bulkToggleable(),
            ])
            ->action(function (array $data): void {
                $user = Auth::user();

                if (! $user instanceof User) {
                    return;
                }

                $user->forceFill([
                    'dashboard_widgets' => app(DashboardLayout::class)->sanitize($data['widgets'] ?? []),
                ])->save();

                Notification::make()
                    ->title('Visual report updated')
                    ->success()
                    ->send();

                $this->redirect(static::getUrl());
            });
    }

    protected function restoreReportAction(): Action
    {
        return Action::make('restoreReport')
            ->label('All charts')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Restore every chart?')
            ->modalDescription('Your saved chart selection will be cleared and the full visual report will show again.')
            ->visible(fn (): bool => is_array(Auth::user() instanceof User ? Auth::user()->dashboard_widgets : null))
            ->action(function (): void {
                $user = Auth::user();

                if (! $user instanceof User) {
                    return;
                }

                $user->forceFill(['dashboard_widgets' => null])->save();

                Notification::make()
                    ->title('All charts restored')
                    ->success()
                    ->send();

                $this->redirect(static::getUrl());
            });
    }

    public function doPunchIn(array $data): void
    {
        $employee = Auth::user()->employee;

        if (! isset($data['latitude'], $data['longitude'])) {
            Notification::make()
                ->title('Location permission not allowed')
                ->danger()
                ->send();

            return;
        }

        if (! $employee) {
            Notification::make()
                ->title('Employee not linked')
                ->danger()
                ->send();

            return;
        }

        $shift = $employee->currentShiftForDate(today());

        if (! $shift) {
            Notification::make()
                ->title('Shift not assigned')
                ->body('No active shift is assigned for today. Please contact HR.')
                ->danger()
                ->send();

            return;
        }

        $firstPunchIn = now()->format('H:i');

        $attendance = DailyAttendance::create([
            'employee_id' => $employee->id,
            'shift_master_id' => $shift->id,
            'attendance_date' => today(),
            'first_punch_in' => $firstPunchIn,
            'status_id' => EmployeeAttendanceStatus::where('status_code', 'SP')->value('id'),
        ]);

        AttendancePunch::create([
            'employee_id' => $employee->id,
            'punch_date' => today(),
            'punch_time' => $firstPunchIn,
            'punch_type' => 'in',
            'source' => 'punch-in-page',
            'raw_payload' => [
                'ip' => Request::ip(),
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
            ],
        ]);

        Notification::make()
            ->title('Punch In Successfully!')
            ->success()
            ->send();
    }

    public function doPunchOut(array $data): void
    {
        $employee = Auth::user()->employee;

        if (! isset($data['latitude'], $data['longitude'])) {
            Notification::make()
                ->title('Location permission not allowed')
                ->danger()
                ->send();

            return;
        }

        $todayAttendance = DailyAttendance::where('employee_id', $employee->id)
            ->whereDate('attendance_date', today())
            ->first();

        if (! $todayAttendance) {
            Notification::make()
                ->title('No attendance record found for today')
                ->danger()
                ->send();

            return;
        }

        // Insert OUT punch
        AttendancePunch::create([
            'employee_id' => $employee->id,
            'punch_date' => today(),
            'punch_time' => now()->format('H:i'),
            'punch_type' => 'out',
            'source' => 'dashboard',
            'raw_payload' => [
                'ip' => Request::ip(),
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
            ],
        ]);

        // Process attendance (calculate hours, status, etc.)
        dispatch_sync(new ProcessDailyAttendanceJob($todayAttendance->id));

        Notification::make()
            ->title('Checked Out Successfully!')
            ->success()
            ->send();

        // Refresh the dashboard to update button state
        $this->dispatch('$refresh');
    }
}
