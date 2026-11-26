<?php

namespace App\Filament\Pages;

use App\Services\Field\FieldAttendanceService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;

class PunchIn extends Page
{
    protected static ?int $navigationSort = 20;

    protected static string|\UnitEnum|null $navigationGroup = 'My Workspace';

    protected string $view = 'filament.pages.punch-in';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static ?string $title = 'Punch In/Out';

    protected static ?string $navigationLabel = 'Punch In/Out';

    public static function canAccess(): bool
    {
        return Auth::user()?->canPunchAttendance() ?? false;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('punchIn')
                ->label('👋 Punch In')
                ->button()
                ->color('success')
                ->icon('heroicon-o-clock')
                ->visible(fn (): bool => $this->punchState === 'in')
                ->alpineClickHandler('startPunchIn($wire)'),
            Action::make('punchOut')
                ->label('🚪 Punch Out')
                ->color('danger')
                ->icon('heroicon-o-arrow-right-on-rectangle')
                ->button()
                ->visible(fn (): bool => $this->punchState === 'out')
                ->alpineClickHandler('startPunchOut($wire)'),
            Action::make('completed')
                ->label('✔ Attendance Completed')
                ->color('gray')
                ->disabled()
                ->visible(fn (): bool => $this->punchState === 'completed')
                ->icon('heroicon-o-check-circle'),
        ];
    }

    #[Computed]
    public function punchState(): string
    {
        $user = Auth::user();

        if (! $user?->employee) {
            return 'unavailable';
        }

        $attendance = app(FieldAttendanceService::class)->today($user);

        if (! $attendance) {
            return 'in';
        }

        return $attendance->punches()->where('punch_type', 'out')->exists() ? 'completed' : 'out';
    }

    public function doPunchIn(array $data): void
    {
        abort_unless(Auth::user()?->canPunchAttendance(), 403);

        try {
            app(FieldAttendanceService::class)->punchIn(Auth::user(), [
                ...$this->validateCoordinates($data),
                'source' => 'punch-in-page',
            ]);
        } catch (ValidationException $exception) {
            $this->notifyPunchFailure($exception);

            return;
        }

        Notification::make()
            ->title('Punch In Successfully!')
            ->success()
            ->send();

        unset($this->punchState);
    }

    public function doPunchOut(array $data): void
    {
        abort_unless(Auth::user()?->canPunchAttendance(), 403);

        try {
            app(FieldAttendanceService::class)->punchOut(Auth::user(), [
                ...$this->validateCoordinates($data),
                'source' => 'punch-in-page',
            ]);
        } catch (ValidationException $exception) {
            $this->notifyPunchFailure($exception);

            return;
        }

        Notification::make()
            ->title('Checked Out Successfully!')
            ->success()
            ->send();

        unset($this->punchState);
    }

    /** @return array{latitude: numeric, longitude: numeric} */
    protected function validateCoordinates(array $data): array
    {
        return validator($data, [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ])->validate();
    }

    protected function notifyPunchFailure(ValidationException $exception): void
    {
        Notification::make()
            ->title('Unable to record attendance')
            ->body(collect($exception->errors())->flatten()->implode("\n"))
            ->danger()
            ->send();
    }
}
